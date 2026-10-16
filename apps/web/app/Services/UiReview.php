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
        $complete = array_map(fn ($s) => $s->value, array_filter(BuildStatus::cases(), fn ($s) => $s->isComplete()));
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
            if ($a?->image_sha === $b?->image_sha) {
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
