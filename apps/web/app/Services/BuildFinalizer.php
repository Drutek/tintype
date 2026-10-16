<?php

namespace App\Services;

use App\Enums\BuildStatus;
use App\Enums\ReviewState;
use App\Enums\SnapshotStatus;
use App\Events\BuildUpdated;
use App\Jobs\ReportBuildToGitHub;
use App\Jobs\ReportBuildToScm;
use App\Jobs\SendBuildNotifications;
use App\Models\Build;
use App\Models\CaptureJob;
use App\Models\Review;
use App\Models\Snapshot;
use App\Models\Story;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class BuildFinalizer
{
    public function __construct(private readonly BaselineResolver $baselines) {}

    public function finalize(Build $build): void
    {
        Cache::lock('finalize-build-'.$build->id, 30)->block(10, function () use ($build) {
            $build->refresh();
            if (! $build->status->isInProgress()) {
                return;
            }
            DB::transaction(function () use ($build) {
                $this->carryForward($build);
                $this->autoAccept($build);
                $this->recount($build);
                $failedJobs = CaptureJob::where('build_id', $build->id)->where('status', 'failed')->count();
                $build->forceFill([
                    'status' => $failedJobs > 0 && $build->snapshot_count === $build->error_count
                        ? BuildStatus::Failed
                        : $this->statusFor($build),
                    'completed_at' => now(),
                ])->save();
            });
        });
        BuildUpdated::dispatch($build);
        $this->afterStatusChange($build);
    }

    /**
     * Keys present in the baseline but not captured in this build:
     *  - story still exists but wasn't captured (onlyStoryNames / TurboSnap): inherit as unchanged
     *  - story (or mode) gone: record as removed
     */
    private function carryForward(Build $build): void
    {
        $resolved = $this->baselines->resolve($build);
        if (! $resolved) {
            return;
        }
        $existing = Snapshot::where('build_id', $build->id)->get()->keyBy(fn (Snapshot $s) => $s->key());
        $capturedStories = $existing->pluck('story_id')->unique()->flip();
        $indexStoryIds = Story::where('project_id', $build->project_id)
            ->whereIn('story_id', $build->options['storyIds'] ?? [])
            ->pluck('id')
            ->flip();

        foreach ($resolved as $key => $baseline) {
            if ($existing->has($key)) {
                continue;
            }
            [$storyId, $browser, $modeKey] = explode('|', $key);
            $storyId = (int) $storyId;
            if (! in_array($browser, $build->browsers, true)) {
                continue;
            }
            $inIndex = $indexStoryIds->has($storyId);
            $wasCaptured = $capturedStories->has($storyId);
            if (! $baseline) {
                continue; // never approved: nothing to inherit or mark removed
            }
            $inherit = $inIndex && ! $wasCaptured;
            Snapshot::create([
                'build_id' => $build->id,
                'story_id' => $storyId,
                'browser' => $browser,
                'mode_key' => $modeKey,
                'mode_name' => $baseline->mode_name ?? 'default',
                'viewport_width' => $baseline->viewport_width,
                'viewport_height' => $baseline->viewport_height,
                'status' => $inherit ? SnapshotStatus::Unchanged : SnapshotStatus::Removed,
                'image_sha' => $inherit ? $baseline->image_sha : null,
                'width' => $inherit ? $baseline->width : null,
                'height' => $inherit ? $baseline->height : null,
                'baseline_snapshot_id' => $baseline->id,
                'ignore_regions' => $baseline->ignore_regions,
                'a11y' => $inherit ? $baseline->a11y : null,
                'params' => $inherit ? array_merge($baseline->params ?? [], ['inherited' => true]) : null,
            ]);
        }
    }

    private function autoAccept(Build $build): void
    {
        $project = $build->project;
        $source = null;
        if (! empty($build->options['autoAcceptChanges'])) {
            $source = 'cli';
        } elseif ($build->baseline_strategy === 'none' && ! Build::where('project_id', $build->project_id)->where('id', '<', $build->id)->whereNotIn('status', [BuildStatus::Failed->value, BuildStatus::Canceled->value])->exists()) {
            $source = 'first-build';
        } elseif ($project->setting('rebaseline_pending') && $build->branch === $project->default_branch) {
            // Admin asked to re-baseline (e.g. after a capture image upgrade): accept this build wholesale.
            $source = 'rebaseline';
            $project->forceFill(['settings' => array_merge($project->settings ?? [], ['rebaseline_pending' => false])])->save();
        } elseif ($project->setting('auto_accept_default_branch') && $build->branch === $project->default_branch) {
            $source = 'default-branch';
        }
        if (! $source) {
            return;
        }
        $pending = Snapshot::where('build_id', $build->id)->where('review_state', ReviewState::Pending->value)->get();
        foreach ($pending as $snap) {
            $snap->update(['review_state' => ReviewState::Accepted, 'reviewed_at' => now()]);
            Review::create(['snapshot_id' => $snap->id, 'decision' => 'accepted', 'source' => $source]);
        }
        $build->options = array_merge($build->options ?? [], ['autoAccepted' => $source]);
    }

    public function recount(Build $build): void
    {
        $counts = Snapshot::where('build_id', $build->id)
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');
        $c = fn (SnapshotStatus $s) => (int) ($counts[$s->value] ?? 0);
        $build->forceFill([
            'snapshot_count' => (int) $counts->sum() - $c(SnapshotStatus::Removed),
            'changed_count' => $c(SnapshotStatus::Changed),
            'new_count' => $c(SnapshotStatus::New),
            'unchanged_count' => $c(SnapshotStatus::Unchanged),
            'error_count' => $c(SnapshotStatus::Error),
            'skipped_count' => $c(SnapshotStatus::Skipped),
            'removed_count' => $c(SnapshotStatus::Removed),
            'inconsistent_count' => $c(SnapshotStatus::Inconsistent),
            'a11y_count' => Snapshot::where('build_id', $build->id)->whereNotNull('a11y')->get(['a11y'])->sum(fn (Snapshot $s) => count($s->a11y ?? [])),
            'a11y_new_count' => (int) Snapshot::where('build_id', $build->id)->sum('a11y_new_count'),
        ]);
    }

    /** Status from snapshot states (used at finalize and after each review). */
    public function statusFor(Build $build): BuildStatus
    {
        $states = Snapshot::where('build_id', $build->id)
            ->whereNotNull('review_state')
            ->selectRaw('review_state, count(*) as c')
            ->groupBy('review_state')
            ->pluck('c', 'review_state');
        $hasErrors = Snapshot::where('build_id', $build->id)->where('status', SnapshotStatus::Error->value)->exists();
        if ($hasErrors) {
            return BuildStatus::Broken;
        }
        if (($states[ReviewState::Pending->value] ?? 0) > 0) {
            return BuildStatus::Pending;
        }
        if (($states[ReviewState::Denied->value] ?? 0) > 0) {
            return BuildStatus::Denied;
        }
        if (($states[ReviewState::Accepted->value] ?? 0) > 0) {
            return BuildStatus::Accepted;
        }

        return BuildStatus::Passed;
    }

    public function afterStatusChange(Build $build): void
    {
        ReportBuildToGitHub::dispatch($build->id);
        ReportBuildToScm::dispatch($build->id);
        SendBuildNotifications::dispatch($build->id);
    }
}
