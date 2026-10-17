<?php

namespace App\Services;

use App\Enums\BuildStatus;
use App\Enums\SnapshotStatus;
use App\Models\Build;
use App\Models\Commit;
use App\Models\Project;
use App\Models\PullRequest;
use App\Models\Snapshot;
use App\Models\Story;

/**
 * Compares the latest build of a PR with the base-branch build it forked from
 * (nearest base-branch build among the head commit's ancestors).
 */
class UiReview
{
    /** @return array{head: Build|null, base: Build|null, changes: list<array<string, mixed>>} */
    public function compare(Project $project, PullRequest $pr, int $number): array
    {
        $complete = array_values(array_map(fn ($s) => $s->value, array_filter(BuildStatus::cases(), fn ($s) => $s->isComplete())));
        $head = Build::where('project_id', $project->id)
            ->where(fn ($q) => $q->where('pr_number', $number)->when($pr->head_branch, fn ($q) => $q->orWhere('branch', $pr->head_branch)))
            ->whereIn('status', $complete)->orderByDesc('number')->first();
        if (! $head) {
            return ['head' => null, 'base' => null, 'changes' => []];
        }
        $baseBranch = $pr->base_branch ?? $project->default_branch;
        $base = $this->forkPoint($project, $head, $baseBranch, $complete);

        $after = $this->approvedOrCurrent($head);
        $before = $base ? $this->approvedOrCurrent($base) : [];
        $storyIds = array_unique(array_map(fn ($k) => (int) explode('|', $k)[0], array_merge(array_keys($after), array_keys($before))));
        $stories = Story::whereIn('id', $storyIds)->get()->keyBy('id');

        $changes = [];
        foreach (array_unique(array_merge(array_keys($after), array_keys($before))) as $key) {
            $a = $after[$key] ?? null;
            $b = $before[$key] ?? null;
            if ($a?->image_sha === $b?->image_sha || $this->matchedWithinThreshold($a, $b)) {
                continue;
            }
            [$sid, $browser] = explode('|', $key);
            $changes[] = [
                'key' => $key,
                'story' => $stories[(int) $sid],
                'browser' => $browser,
                'mode' => ($a ?? $b)->mode_name,
                'kind' => ! $b ? 'added' : (! $a ? 'removed' : 'changed'),
                'before' => $b,
                'after' => $a,
            ];
        }
        usort($changes, fn ($x, $y) => strcmp($x['story']->title.$x['story']->name, $y['story']->title.$y['story']->name));

        return ['head' => $head, 'base' => $base, 'changes' => $changes];
    }

    /**
     * Hash of what a reviewer saw: each changed snapshot and the image it changed to.
     *
     * @param  list<array<string, mixed>>  $changes
     */
    public function fingerprint(array $changes): string
    {
        $parts = array_map(fn ($c) => $c['key'].'='.($c['after'] instanceof Snapshot ? $c['after']->image_sha : '-'), $changes);
        sort($parts);

        return hash('sha256', implode("\n", $parts));
    }

    /**
     * Dismiss an approval (or change request) once the PR's UI changes differ from
     * the ones it was made on, e.g. after a new push.
     *
     * @param  array{head: Build|null, base: Build|null, changes: list<array<string, mixed>>}  $comparison
     */
    public function dismissStaleReview(PullRequest $pr, array $comparison): void
    {
        if (! $pr->exists || ! in_array($pr->review_status, ['approved', 'changes_requested'], true)
            || $pr->review_fingerprint === $this->fingerprint($comparison['changes'])) {
            return;
        }
        $pr->update(['review_status' => 'open', 'review_fingerprint' => null]);
        $pr->reviews()->create(['state' => 'dismissed', 'body' => 'UI changed since the last review'.($comparison['head'] ? " (build {$comparison['head']->number})" : '')]);
    }

    /**
     * The head snapshot was judged unchanged, directly or through a chain of unchanged
     * rebuilds, against this same base image (sub-threshold diffs).
     */
    private function matchedWithinThreshold(?Snapshot $after, ?Snapshot $before): bool
    {
        for ($s = $after, $hops = 0; $before && $s?->status === SnapshotStatus::Unchanged && $hops < 20; $hops++) {
            $s = $s->baseline;
            if ($s?->image_sha === $before->image_sha) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $complete */
    private function forkPoint(Project $project, Build $head, string $baseBranch, array $complete): ?Build
    {
        $byCommit = Build::where('project_id', $project->id)->where('branch', $baseBranch)->whereIn('status', $complete)
            ->orderByDesc('number')->get()->unique('commit_sha')->keyBy('commit_sha');
        if ($byCommit->isEmpty()) {
            return null;
        }
        $graph = Commit::where('project_id', $project->id)->pluck('parents', 'sha')->map(fn ($p) => is_string($p) ? json_decode($p, true) : $p)->all();
        $queue = [$head->commit_sha];
        $seen = [];
        while ($queue && count($seen) < (int) config('tintype.max_ancestor_commits', 1000)) {
            $sha = array_shift($queue);
            if (isset($seen[$sha])) {
                continue;
            }
            $seen[$sha] = true;
            if ($byCommit->has($sha)) {
                return $byCommit->get($sha);
            }
            foreach ($graph[$sha] ?? [] as $p) {
                $queue[] = $p;
            }
        }

        return $byCommit->first();
    }

    /** @return array<string, Snapshot> */
    private function approvedOrCurrent(Build $build): array
    {
        $out = [];
        foreach (Snapshot::where('build_id', $build->id)->whereNot('status', SnapshotStatus::Removed->value)->with('baseline')->get() as $s) {
            if ($s->image_sha) {
                $out[$s->key()] = $s;
            }
        }

        return $out;
    }
}
