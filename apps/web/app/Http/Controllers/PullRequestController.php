<?php

namespace App\Http\Controllers;

use App\Models\Build;
use App\Models\Image;
use App\Models\Organization;
use App\Models\Project;
use App\Models\PullRequest;
use App\Models\PullRequestReview;
use App\Models\User;
use App\Services\PullRequestReviewer;
use App\Services\UiReview;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * UI Review: everything a PR changes visually, compared with its base branch,
 * reviewed like code (approve / request changes, threads).
 */
class PullRequestController extends Controller
{
    use Concerns;

    public function index(Request $request, Organization $organization, Project $project): Response
    {
        $this->scoped($organization, $project);
        $this->role($request, $organization);
        $numbers = $project->builds()->whereNotNull('pr_number')->distinct()->pluck('pr_number');
        $known = PullRequest::where('project_id', $project->id)->get()->keyBy('number');
        $rows = $numbers->merge($known->keys())->unique()->sortDesc()->values()->map(function ($n) use ($project, $known) {
            $pr = $known->get($n);
            $latest = $project->builds()->where('pr_number', $n)->orderByDesc('number')->first();

            return [
                'number' => (int) $n,
                'title' => $pr?->title,
                'state' => $pr->state ?? 'open',
                'reviewStatus' => $pr->review_status ?? 'open',
                'headBranch' => $pr->head_branch ?? $latest?->branch,
                'baseBranch' => $pr->base_branch ?? $project->default_branch,
                'author' => $pr?->author,
                'latestBuild' => $latest ? BuildController::row($latest) : null,
            ];
        });

        return Inertia::render('pulls/index', [
            'organization' => $organization->only('id', 'name', 'slug'),
            'project' => ProjectController::projectProps($project),
            'pulls' => $rows,
        ]);
    }

    public function show(Request $request, Organization $organization, Project $project, int $number, UiReview $ui): Response
    {
        $this->scoped($organization, $project);
        $role = $this->role($request, $organization);
        $pr = PullRequest::firstOrNew(['project_id' => $project->id, 'number' => $number]);
        $comparison = $ui->compare($project, $pr, $number);
        $ui->dismissStaleReview($pr, $comparison);
        $reviews = $pr->exists ? $pr->reviews()->with('user:id,name')->latest()->get() : collect();

        return Inertia::render('pulls/show', [
            'organization' => $organization->only('id', 'name', 'slug'),
            'project' => ProjectController::projectProps($project),
            'role' => $role->value,
            'pull' => [
                'number' => $number,
                'title' => $pr->title,
                'state' => $pr->state ?? 'open',
                'reviewStatus' => $pr->review_status ?? 'open',
                'headBranch' => $pr->head_branch ?? $comparison['head']?->branch,
                'baseBranch' => $pr->base_branch ?? $project->default_branch,
                'url' => $pr->url,
                'author' => $pr->author,
                'assignees' => User::whereIn('id', $pr->assignees ?? [])->get(['id', 'name'])->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])->values(),
            ],
            'members' => $organization->users()->orderBy('name')->get(['users.id', 'users.name'])->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])->values(),
            'head' => $comparison['head'] ? BuildController::row($comparison['head']) : null,
            // Snapshots in the head build still awaiting a decision: these keep the Visual Tests check open.
            'pendingInHead' => $comparison['head'] ? PullRequestReviewer::pendingIn($comparison['head'])->count() : 0,
            'base' => $comparison['base'] ? BuildController::row($comparison['base']) : null,
            'changes' => collect($comparison['changes'])->map(fn ($c) => [
                'key' => $c['key'],
                'storyId' => $c['story']->story_id,
                'title' => $c['story']->title,
                'name' => $c['story']->name,
                'browser' => $c['browser'],
                'mode' => $c['mode'],
                'kind' => $c['kind'],
                'before' => Image::urlFor($c['before']?->image_sha),
                'after' => Image::urlFor($c['after']?->image_sha),
                'afterSnapshotId' => $c['after']?->id,
                'reviewState' => $c['after']?->review_state?->value,
            ])->values(),
            'reviews' => $reviews->map(fn (PullRequestReview $r) => [
                'id' => $r->id, 'state' => $r->state, 'body' => $r->body, 'user' => $r->user?->name, 'createdAt' => $r->created_at->toIso8601String(),
            ]),
        ]);
    }

    /** Assign (or unassign) a reviewer to a pull request's UI review. */
    public function assign(Request $request, Organization $organization, Project $project, int $number): RedirectResponse
    {
        $this->scoped($organization, $project);
        $this->requireReviewer($request, $organization);
        $data = $request->validate(['user_id' => ['required', 'integer'], 'remove' => ['boolean']]);
        abort_unless($organization->users()->whereKey($data['user_id'])->exists(), 422, 'Not a member of this organization');
        $pr = PullRequest::firstOrCreate(['project_id' => $project->id, 'number' => $number], ['base_branch' => $project->default_branch]);
        $assignees = collect($pr->assignees ?? [])->map(fn ($id) => (int) $id);
        $assignees = ($data['remove'] ?? false) ? $assignees->reject(fn ($id) => $id === $data['user_id']) : $assignees->push($data['user_id'])->unique();
        $pr->update(['assignees' => $assignees->values()->all()]);
        if (! ($data['remove'] ?? false)) {
            $pr->reviews()->create(['user_id' => $request->user()->id, 'state' => 'assigned', 'body' => (string) User::whereKey($data['user_id'])->value('name')]);
        }

        return back();
    }

    public function review(Request $request, Organization $organization, Project $project, int $number, PullRequestReviewer $reviewer): RedirectResponse
    {
        $this->scoped($organization, $project);
        $this->requireReviewer($request, $organization);
        $data = $request->validate([
            'state' => ['required', Rule::in(['approved', 'changes_requested', 'commented'])],
            'body' => ['nullable', 'string', 'max:5000'],
            'accept_pending' => ['boolean'],
        ]);
        try {
            $reviewer->review($project, $number, $request->user(), $data['state'], $data['body'] ?? null, (bool) ($data['accept_pending'] ?? false));
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['review' => $e->getMessage()]);
        }

        return back();
    }
}
