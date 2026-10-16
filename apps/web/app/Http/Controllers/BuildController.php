<?php

namespace App\Http\Controllers;

use App\Enums\ReviewState;
use App\Enums\SnapshotStatus;
use App\Models\Build;
use App\Models\Comment;
use App\Models\Image;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Review;
use App\Models\Snapshot;
use App\Services\ReviewService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class BuildController extends Controller
{
    use Concerns;

    private const ORDER = ['changed' => 0, 'new' => 1, 'error' => 2, 'inconsistent' => 3, 'removed' => 4, 'unchanged' => 5, 'skipped' => 6];

    private function resolveBuild(Organization $organization, Project $project, int $number): Build
    {
        $this->scoped($organization, $project);
        $build = $project->builds()->where('number', $number)->firstOrFail();
        $build->setRelation('project', $project);

        return $build;
    }

    public function show(Request $request, Organization $organization, Project $project, int $build): Response
    {
        $role = $this->role($request, $organization);
        $build = $this->resolveBuild($organization, $project, $build);

        $snapshots = $build->snapshots()
            ->with(['story', 'baseline', 'reviewer:id,name', 'reviews' => fn ($q) => $q->with('user:id,name')->latest('id')])
            ->withCount('comments')
            ->get()
            ->sortBy([
                fn (Snapshot $a, Snapshot $b) => (self::ORDER[$a->status->value] ?? 9) <=> (self::ORDER[$b->status->value] ?? 9),
                fn (Snapshot $a, Snapshot $b) => strcmp($a->story->title.$a->story->name.$a->mode_name, $b->story->title.$b->story->name.$b->mode_name),
            ])
            ->values()
            ->map(fn (Snapshot $s) => self::snapshotProps($s));

        $baselineBuilds = Build::whereIn('id', $build->baseline_build_ids ?? [])->get(['id', 'number', 'branch', 'commit_sha']);

        return Inertia::render('builds/show', [
            'organization' => $organization->only('id', 'name', 'slug'),
            'project' => ProjectController::projectProps($project),
            'role' => $role->value,
            'build' => self::row($build) + [
                'id' => $build->id,
                'error' => $build->error,
                'baselineStrategy' => $build->baseline_strategy,
                'baselineBuilds' => $baselineBuilds->map(fn ($b) => ['number' => $b->number, 'branch' => $b->branch, 'commit' => $b->commit_sha]),
                'captureImage' => $build->capture_image,
                'ci' => $build->ci,
                'autoAccepted' => $build->options['autoAccepted'] ?? null,
                'storybookUrl' => $build->storybookUrl(),
                'startedAt' => $build->started_at?->toIso8601String(),
                'completedAt' => $build->completed_at?->toIso8601String(),
                'progress' => [
                    'total' => $build->captureJobs()->count(),
                    'done' => $build->captureJobs()->whereIn('status', ['completed', 'failed'])->count(),
                ],
            ],
            'snapshots' => $snapshots,
            'comments' => Comment::where('build_id', $build->id)->whereNull('parent_id')->with(['user:id,name', 'replies.user:id,name'])->orderBy('created_at')->get()
                ->map(fn (Comment $c) => self::commentProps($c)),
            'selected' => $request->integer('snapshot') ?: null,
        ]);
    }

    public function review(Request $request, Organization $organization, Project $project, int $build, ReviewService $reviews): RedirectResponse
    {
        $this->requireReviewer($request, $organization);
        $build = $this->resolveBuild($organization, $project, $build);
        $data = $request->validate([
            'decision' => ['required', Rule::in(['accepted', 'denied', 'pending'])],
            'snapshotIds' => ['array'],
            'snapshotIds.*' => ['integer'],
            'scope' => ['nullable', Rule::in(['selection', 'all', 'component'])],
            'component' => ['nullable', 'string'],
        ]);
        $query = $build->snapshots()->whereNotNull('review_state');
        $scope = $data['scope'] ?? 'selection';
        if ($scope === 'selection') {
            $query->whereIn('id', $data['snapshotIds'] ?? []);
        } elseif ($scope === 'component') {
            $query->whereHas('story', fn ($q) => $q->where('title', $data['component'] ?? ''));
        }
        if ($data['decision'] !== 'pending' && $scope !== 'selection') {
            $query->where('review_state', ReviewState::Pending->value);
        }
        try {
            $reviews->decide($build, $query->get(), $data['decision'], $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['review' => $e->getMessage()]);
        }

        return back();
    }

    public function comment(Request $request, Organization $organization, Project $project, int $build): RedirectResponse
    {
        $this->requireReviewer($request, $organization);
        $build = $this->resolveBuild($organization, $project, $build);
        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'snapshot_id' => ['nullable', 'integer'],
            'parent_id' => ['nullable', 'integer'],
        ]);
        if ($data['snapshot_id'] ?? null) {
            abort_unless($build->snapshots()->whereKey($data['snapshot_id'])->exists(), 422);
        }
        if ($data['parent_id'] ?? null) {
            abort_unless(Comment::where('build_id', $build->id)->whereKey($data['parent_id'])->exists(), 422);
        }
        Comment::create($data + ['build_id' => $build->id, 'user_id' => $request->user()->id]);

        return back();
    }

    public function resolveComment(Request $request, Organization $organization, Project $project, int $build, Comment $comment): RedirectResponse
    {
        $this->requireReviewer($request, $organization);
        $build = $this->resolveBuild($organization, $project, $build);
        abort_unless($comment->build_id === $build->id, 404);
        $comment->update(['resolved_at' => $comment->resolved_at ? null : now()]);

        return back();
    }

    /** @return array<string, mixed> */
    public static function row(Build $build): array
    {
        return [
            'number' => $build->number,
            'status' => $build->status->value,
            'branch' => $build->branch,
            'commit' => $build->commit_sha,
            'prNumber' => $build->pr_number,
            'createdAt' => $build->created_at->toIso8601String(),
            'counts' => [
                'snapshots' => $build->snapshot_count,
                'changed' => $build->changed_count,
                'new' => $build->new_count,
                'errors' => $build->error_count,
                'unchanged' => $build->unchanged_count,
                'skipped' => $build->skipped_count,
                'removed' => $build->removed_count,
                'inconsistent' => $build->inconsistent_count,
                'stories' => $build->story_count,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function snapshotProps(Snapshot $s): array
    {
        $baseline = $s->baseline;

        return [
            'id' => $s->id,
            'storyId' => $s->story->story_id,
            'title' => $s->story->title,
            'name' => $s->story->name,
            'importPath' => $s->story->import_path,
            'browser' => $s->browser,
            'mode' => $s->mode_name,
            'modeKey' => $s->mode_key,
            'viewport' => $s->viewport_width ? ['width' => $s->viewport_width, 'height' => $s->viewport_height] : null,
            'status' => $s->status->value,
            'review' => $s->review_state?->value,
            'reviewedBy' => $s->reviewer?->name,
            'image' => Image::urlFor($s->image_sha),
            'width' => $s->width,
            'height' => $s->height,
            'baseline' => $baseline ? [
                'id' => $baseline->id,
                'image' => Image::urlFor($baseline->image_sha),
                'width' => $baseline->width,
                'height' => $baseline->height,
                'buildId' => $baseline->build_id,
            ] : null,
            'diff' => Image::urlFor($s->diff_sha),
            'diffRatio' => $s->diff_ratio,
            'diffRegions' => $s->diff_regions ?? [],
            'ignoreRegions' => $s->ignore_regions ?? [],
            'error' => $s->error,
            'inherited' => (bool) ($s->params['inherited'] ?? false),
            'commentCount' => $s->comments_count ?? 0,
            'needsReview' => $s->status->needsReview(),
            'unchangedSinceBaseline' => $s->status === SnapshotStatus::Unchanged,
            'history' => ($s->relationLoaded('reviews') ? $s->reviews : $s->reviews()->with('user:id,name')->latest('id')->get())->take(10)->values()
                ->map(fn (Review $r) => ['decision' => $r->decision, 'source' => $r->source, 'user' => $r->user?->name, 'at' => $r->created_at->toIso8601String()]),
        ];
    }

    /** @return array<string, mixed> */
    public static function commentProps(Comment $c): array
    {
        return [
            'id' => $c->id,
            'snapshotId' => $c->snapshot_id,
            'body' => $c->body,
            'user' => $c->user?->name,
            'createdAt' => $c->created_at->toIso8601String(),
            'resolved' => $c->resolved_at !== null,
            'replies' => $c->replies->map(fn (Comment $r) => ['id' => $r->id, 'body' => $r->body, 'user' => $r->user?->name, 'createdAt' => $r->created_at->toIso8601String()]),
        ];
    }
}
