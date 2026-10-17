<?php

namespace App\Services;

use App\Enums\BuildStatus;
use App\Enums\ReviewState;
use App\Enums\SnapshotStatus;
use App\Events\BuildUpdated;
use App\Models\Build;
use App\Models\CaptureJob;
use App\Models\Image;
use App\Models\Snapshot;
use App\Models\Story;
use App\Models\Worker;
use Illuminate\Support\Facades\DB;

class CaptureService
{
    public function __construct(
        private readonly BaselineResolver $baselines,
        private readonly BuildFinalizer $finalizer,
    ) {}

    /** @param array<string, mixed> $info */
    public function heartbeatWorker(array $info): Worker
    {
        return Worker::updateOrCreate(['worker_id' => $info['workerId']], [
            'arch' => $info['arch'] ?? null,
            'image' => $info['image'] ?? null,
            'browsers' => $info['browsers'] ?? ['chromium'],
            'browser_version' => $info['browserVersion'] ?? null,
            'last_seen_at' => now(),
        ]);
    }

    /**
     * Lease the next available shard for a worker. Expired leases are reclaimed;
     * shards that exhaust their attempts are failed so the build can finish.
     *
     * @param  array<string, mixed>  $info
     * @return array<string, mixed>|null
     */
    public function lease(array $info): ?array
    {
        $this->heartbeatWorker($info);
        $this->reapExpired();

        $browsers = $info['browsers'] ?? ['chromium'];
        for ($tries = 0; $tries < 5; $tries++) {
            $job = CaptureJob::query()
                ->whereIn('browser', $browsers)
                ->where('status', 'queued')
                ->orderBy('id')
                ->first();
            if (! $job) {
                return null;
            }
            $claimed = CaptureJob::whereKey($job->id)->where('status', 'queued')->update([
                'status' => 'leased',
                'worker_id' => $info['workerId'],
                'lease_expires_at' => now()->addSeconds(CaptureJob::LEASE_SECONDS),
                'attempts' => $job->attempts + 1,
                'updated_at' => now(),
            ]);
            if ($claimed) {
                $job->refresh();

                return $this->payload($job, $info);
            }
        }

        return null;
    }

    public function reapExpired(): void
    {
        $expired = CaptureJob::where('status', 'leased')->where('lease_expires_at', '<', now())->get();
        foreach ($expired as $job) {
            if ($job->attempts >= CaptureJob::MAX_ATTEMPTS) {
                $this->failJob($job, 'Capture timed out after '.$job->attempts.' attempts');
            } else {
                CaptureJob::whereKey($job->id)->where('status', 'leased')->update(['status' => 'queued', 'worker_id' => null]);
            }
        }
    }

    /** @param array<string, mixed> $info
     * @return array<string, mixed> */
    private function payload(CaptureJob $job, array $info): array
    {
        $build = $job->build()->with('project')->firstOrFail();
        if ($build->status === BuildStatus::Queued || $build->capture_image === null) {
            $build->forceFill([
                'status' => BuildStatus::Capturing,
                'capture_image' => $build->capture_image ?? ($info['image'] ?? null),
            ])->save();
            BuildUpdated::dispatch($build);
        }
        $settings = $build->project->resolvedSettings();

        $stories = Story::where('project_id', $build->project_id)->whereIn('story_id', $job->story_ids)->get();
        $resolved = $this->baselines->resolve($build, array_values($stories->map(fn (Story $s): int => $s->id)->all()));
        $byStory = [];
        foreach ($stories as $story) {
            $modes = [];
            foreach ($resolved as $key => $snap) {
                [$sid, $browser, $mode] = explode('|', $key);
                if ((int) $sid !== $story->id || $browser !== $job->browser || ! $snap?->image_sha) {
                    continue;
                }
                $modes[$mode] = [
                    'snapshotId' => $snap->id,
                    'sha' => $snap->image_sha,
                    'url' => Build::internalUrl('/api/internal/v1/images/'.$snap->image_sha),
                    'ignoreRegions' => $snap->ignore_regions ?? [],
                ];
            }
            $byStory[] = ['storyId' => $story->story_id, 'baselines' => (object) $modes];
        }

        return [
            'job' => [
                'id' => $job->id,
                'buildId' => $build->id,
                'attempt' => $job->attempts,
                'browser' => $job->browser,
                'storybookUrl' => $build->internalStorybookUrl(),
                'storybookArchiveUrl' => Build::internalUrl("/api/internal/v1/builds/{$build->id}/storybook.zip"),
                'stories' => $byStory,
                'settings' => [
                    'diffThreshold' => (float) $settings['diff_threshold'],
                    'includeAntiAliasing' => (bool) $settings['include_anti_aliasing'],
                    'allowedHosts' => array_values((array) $settings['allowed_hosts']),
                    'fixedTime' => (string) $settings['fixed_time'],
                    'deviceScaleFactor' => (float) $settings['device_scale_factor'],
                    'viewport' => ['width' => (int) $settings['viewport_width'], 'height' => (int) $settings['viewport_height']],
                    'a11y' => (bool) $settings['a11y'],
                ],
                'leaseSeconds' => CaptureJob::LEASE_SECONDS,
            ],
        ];
    }

    public function heartbeat(CaptureJob $job, string $workerId): bool
    {
        return (bool) CaptureJob::whereKey($job->id)
            ->where('status', 'leased')
            ->where('worker_id', $workerId)
            ->update(['lease_expires_at' => now()->addSeconds(CaptureJob::LEASE_SECONDS)]);
    }

    /**
     * Record capture results posted by a worker.
     *
     * @param  list<array<string, mixed>>  $results
     */
    public function recordResults(CaptureJob $job, string $workerId, array $results, bool $complete, ?string $error = null): void
    {
        if ($job->status !== 'leased' || $job->worker_id !== $workerId) {
            return; // stale worker; its lease was reclaimed
        }
        $build = $job->build()->with('project')->firstOrFail();
        $stories = Story::where('project_id', $build->project_id)->whereIn('story_id', $job->story_ids)->get()->keyBy('story_id');
        $resolved = $this->baselines->resolve($build, array_values($stories->map(fn (Story $s): int => $s->id)->all()));

        DB::transaction(function () use ($job, $build, $stories, $resolved, $results) {
            foreach ($results as $r) {
                $story = $stories->get($r['storyId'] ?? '');
                if (! $story) {
                    continue;
                }
                $this->recordOne($build, $story, $job->browser, $r, $resolved);
            }
        });

        if ($complete) {
            $job->update(['status' => $error ? 'failed' : 'completed', 'error' => $error, 'lease_expires_at' => null]);
            // Any story the worker never reported becomes an error rather than silently vanishing.
            $this->markMissingAsErrors($job, $error ?? 'The capture worker did not report a result for this story');
            $this->maybeFinalize($build);
        } else {
            $this->heartbeat($job, $workerId);
        }
        BuildUpdated::dispatch($build->fresh());
    }

    /** @param array<string, mixed> $r
     * @param array<string, Snapshot|null> $resolved */
    private function recordOne(Build $build, Story $story, string $browser, array $r, array $resolved): void
    {
        $modeKey = (string) ($r['modeKey'] ?? 'default');
        $baseline = $resolved[Snapshot::makeKey($story->id, $browser, $modeKey)] ?? null;
        $imageSha = $r['imageSha'] ?? null;
        if ($imageSha && ! Image::whereKey($imageSha)->exists()) {
            $imageSha = null;
            $r['status'] = 'error';
            $r['error'] = trim(($r['error'] ?? '')."\nImage was not uploaded");
        }
        $diffSha = $r['diffSha'] ?? null;
        if ($diffSha && ! Image::whereKey($diffSha)->exists()) {
            $diffSha = null;
        }

        $status = match ($r['status'] ?? 'error') {
            'skipped' => SnapshotStatus::Skipped,
            'captured' => $this->comparisonStatus($build, $baseline, $imageSha, $r),
            default => SnapshotStatus::Error,
        };
        $review = $status->needsReview() ? ReviewState::Pending : null;
        $params = is_array($r['params'] ?? null) ? $r['params'] : [];
        $a11y = is_array($r['a11y'] ?? null) ? array_values($r['a11y']) : null;
        $known = array_column($baseline->a11y ?? [], 'id');
        $a11yNew = $a11y === null ? 0 : count(array_filter($a11y, fn ($v) => is_array($v) && ! in_array($v['id'] ?? null, $known, true)));
        if ($status === SnapshotStatus::Changed && $this->baselines->captureEnvChanged($build, $baseline)) {
            $params['captureEnvChanged'] = true;
        }

        Snapshot::updateOrCreate(
            ['build_id' => $build->id, 'story_id' => $story->id, 'browser' => $browser, 'mode_key' => $modeKey],
            [
                'mode_name' => (string) ($r['modeName'] ?? 'default'),
                'viewport_width' => $r['viewport']['width'] ?? null,
                'viewport_height' => $r['viewport']['height'] ?? null,
                'status' => $status,
                'review_state' => $review,
                'image_sha' => $imageSha,
                'width' => $r['width'] ?? null,
                'height' => $r['height'] ?? null,
                'baseline_snapshot_id' => $baseline?->id,
                'diff_sha' => $status === SnapshotStatus::Changed && ($r['baselineSnapshotId'] ?? null) === $baseline?->id ? $diffSha : null,
                'diff_ratio' => $status === SnapshotStatus::Changed ? ($r['diffRatio'] ?? null) : null,
                'diff_regions' => $status === SnapshotStatus::Changed ? ($r['diffRegions'] ?? []) : null,
                'ignore_regions' => $r['ignoreRegions'] ?? [],
                'params' => $params,
                'a11y' => $a11y,
                'a11y_new_count' => $a11yNew,
                'error' => $r['error'] ?? null,
                'duration_ms' => $r['durationMs'] ?? null,
            ],
        );
    }

    /** @param array<string, mixed> $r */
    private function comparisonStatus(Build $build, ?Snapshot $baseline, ?string $imageSha, array $r): SnapshotStatus
    {
        if (! $imageSha) {
            return SnapshotStatus::Error;
        }
        if (! $baseline) {
            return SnapshotStatus::New;
        }
        if ($baseline->image_sha === $imageSha) {
            return SnapshotStatus::Unchanged;
        }
        if ($this->baselines->captureEnvChanged($build, $baseline)) {
            return SnapshotStatus::Changed;
        }
        // Trust the worker's pixel comparison only if it compared against our baseline.
        if (($r['baselineSnapshotId'] ?? null) !== $baseline->id) {
            return SnapshotStatus::Changed;
        }

        return match ($r['comparison'] ?? 'changed') {
            'unchanged' => SnapshotStatus::Unchanged,
            'inconsistent' => SnapshotStatus::Inconsistent,
            default => SnapshotStatus::Changed,
        };
    }

    private function failJob(CaptureJob $job, string $error): void
    {
        $job->update(['status' => 'failed', 'error' => $error, 'lease_expires_at' => null]);
        $this->markMissingAsErrors($job, $error);
        $this->maybeFinalize($job->build);
    }

    /** Stories in a failed shard that produced no snapshot become error snapshots. */
    private function markMissingAsErrors(CaptureJob $job, string $error): void
    {
        $build = $job->build;
        $stories = Story::where('project_id', $build->project_id)->whereIn('story_id', $job->story_ids)->get();
        foreach ($stories as $story) {
            $has = Snapshot::where('build_id', $build->id)->where('story_id', $story->id)->where('browser', $job->browser)->exists();
            if (! $has) {
                Snapshot::create([
                    'build_id' => $build->id,
                    'story_id' => $story->id,
                    'browser' => $job->browser,
                    'mode_key' => 'default',
                    'status' => SnapshotStatus::Error,
                    'error' => $error,
                ]);
            }
        }
    }

    public function maybeFinalize(Build $build): void
    {
        $open = CaptureJob::where('build_id', $build->id)->whereIn('status', ['queued', 'leased'])->exists();
        if (! $open) {
            $this->finalizer->finalize($build->fresh());
        }
    }
}
