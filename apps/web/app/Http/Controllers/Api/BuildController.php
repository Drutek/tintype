<?php

namespace App\Http\Controllers\Api;

use App\Enums\BuildStatus;
use App\Enums\SnapshotStatus;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessStorybookUpload;
use App\Models\Build;
use App\Models\Commit;
use App\Models\Project;
use App\Services\BaselineResolver;
use App\Services\BuildService;
use App\Services\ImageStore;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BuildController extends Controller
{
    public function __construct(private readonly BuildService $builds, private readonly ImageStore $store) {}

    private function project(Request $request): Project
    {
        return $request->attributes->get('project');
    }

    private function ownBuild(Request $request, Build $build): Build
    {
        abort_unless($build->project_id === $this->project($request)->id, 404);

        return $build;
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'commit' => ['required', 'string', 'regex:/^[0-9a-f]{7,64}$/i'],
            'branch' => ['required', 'string', 'max:255'],
            'prNumber' => ['nullable', 'integer', 'min:1'],
            'commits' => ['array', 'max:5000'],
            'commits.*.sha' => ['required', 'string', 'regex:/^[0-9a-f]{7,64}$/i'],
            'commits.*.parents' => ['array'],
            'commits.*.parents.*' => ['string'],
            'commits.*.message' => ['nullable', 'string'],
            'commits.*.author' => ['nullable', 'string'],
            'commits.*.committedAt' => ['nullable', 'string'],
            'ci' => ['nullable', 'array'],
            'cliVersion' => ['nullable', 'string', 'max:32'],
            'options' => ['nullable', 'array'],
        ]);
        $build = $this->builds->create($this->project($request), $data);
        $disk = $this->store->disk();
        if ($disk instanceof AwsS3V3Adapter) {
            $signed = $disk->temporaryUploadUrl($build->storybookArchivePath(), now()->addHour(), ['ContentType' => 'application/zip']);
            $upload = ['method' => 'PUT', 'url' => $signed['url'], 'headers' => array_map(fn ($v) => is_array($v) ? $v[0] : $v, $signed['headers'])];
        } else {
            $upload = ['method' => 'PUT', 'url' => route('api.builds.storybook', $build), 'headers' => ['Content-Type' => 'application/zip'], 'authenticated' => true];
        }

        return response()->json([
            'id' => $build->id,
            'number' => $build->number,
            'url' => $build->webUrl(),
            'upload' => $upload,
            'statusUrl' => route('api.builds.show', $build),
        ], 201);
    }

    /**
     * TurboSnap support: which commits would this build be compared against?
     * The CLI diffs its working tree against them to find affected stories.
     */
    public function baselineCommits(Request $request): JsonResponse
    {
        $data = $request->validate([
            'commit' => ['required', 'string', 'regex:/^[0-9a-f]{7,64}$/i'],
            'branch' => ['required', 'string', 'max:255'],
            'commits' => ['array', 'max:5000'],
            'commits.*.sha' => ['required', 'string'],
            'commits.*.parents' => ['array'],
        ]);
        $project = $this->project($request);
        foreach ($data['commits'] ?? [] as $c) {
            Commit::updateOrCreate(['project_id' => $project->id, 'sha' => $c['sha']], ['parents' => array_values($c['parents'] ?? [])]);
        }
        $probe = new Build(['project_id' => $project->id, 'commit_sha' => $data['commit'], 'branch' => $data['branch']]);
        $probe->setRelation('project', $project);
        $result = app(BaselineResolver::class)->ancestorBuilds($probe);

        return response()->json([
            'strategy' => $result['strategy'],
            'commits' => Build::whereIn('id', $result['build_ids'])->pluck('commit_sha')->unique()->values(),
        ]);
    }

    /** Direct upload (local disk deployments): raw zip body. */
    public function uploadStorybook(Request $request, Build $build): JsonResponse
    {
        $this->ownBuild($request, $build);
        abort_unless($build->status === BuildStatus::Uploading, 409, 'Build is not accepting uploads');
        $stream = $request->getContent(true);
        $this->store->disk()->writeStream($build->storybookArchivePath(), $stream);

        return response()->json(['ok' => true]);
    }

    public function uploaded(Request $request, Build $build): JsonResponse
    {
        $this->ownBuild($request, $build);
        abort_unless($build->status === BuildStatus::Uploading, 409, 'Upload already processed');
        abort_unless($this->store->disk()->exists($build->storybookArchivePath()), 422, 'Storybook archive not uploaded');
        ProcessStorybookUpload::dispatch($build->id);

        return response()->json(['ok' => true], 202);
    }

    public function show(Request $request, Build $build): JsonResponse
    {
        $this->ownBuild($request, $build);

        return response()->json(self::summary($build));
    }

    public function cancel(Request $request, Build $build): JsonResponse
    {
        $this->ownBuild($request, $build);
        if ($build->status->isInProgress()) {
            $build->captureJobs()->whereIn('status', ['queued', 'leased'])->update(['status' => 'failed', 'error' => 'Canceled']);
            $build->update(['status' => BuildStatus::Canceled, 'completed_at' => now()]);
        }

        return response()->json(self::summary($build->fresh()));
    }

    /** @return array<string, mixed> */
    public static function summary(Build $build): array
    {
        $jobs = $build->captureJobs()->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');
        $changes = [];
        if ($build->status->isComplete()) {
            $changes = $build->snapshots()->with('story:id,story_id,title,name')
                ->whereIn('status', [SnapshotStatus::Changed->value, SnapshotStatus::New->value, SnapshotStatus::Error->value])
                ->limit(200)->get()
                ->map(fn ($s) => [
                    'storyId' => $s->story->story_id,
                    'title' => $s->story->title,
                    'name' => $s->story->name,
                    'mode' => $s->mode_name,
                    'browser' => $s->browser,
                    'status' => $s->status->value,
                    'review' => $s->review_state?->value,
                    'error' => $s->error ? strtok($s->error, "\n") : null,
                ])->all();
        }

        return [
            'id' => $build->id,
            'number' => $build->number,
            'status' => $build->status->value,
            'exitCode' => $build->status->exitCode(),
            'url' => $build->webUrl(),
            'storybookUrl' => route('storybook.latest', [$build->project->organization, $build->project, 'build' => $build->number]),
            'error' => $build->error,
            'baseline' => ['strategy' => $build->baseline_strategy, 'buildIds' => $build->baseline_build_ids],
            'progress' => ['jobs' => (int) $jobs->sum(), 'done' => (int) (($jobs['completed'] ?? 0) + ($jobs['failed'] ?? 0))],
            'counts' => [
                'stories' => $build->story_count,
                'snapshots' => $build->snapshot_count,
                'changed' => $build->changed_count,
                'new' => $build->new_count,
                'unchanged' => $build->unchanged_count,
                'errors' => $build->error_count,
                'skipped' => $build->skipped_count,
                'removed' => $build->removed_count,
                'inconsistent' => $build->inconsistent_count,
                'a11y' => $build->a11y_count,
                'a11yNew' => $build->a11y_new_count,
            ],
            'autoAccepted' => $build->options['autoAccepted'] ?? null,
            'changes' => $changes,
        ];
    }
}
