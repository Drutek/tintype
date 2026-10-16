<?php

namespace App\Services;

use App\Enums\BuildStatus;
use App\Enums\SnapshotStatus;
use App\Models\Build;
use App\Models\Commit;
use App\Models\PullRequest;
use App\Models\Snapshot;
use Illuminate\Support\Collection;

/**
 * Git-aware baseline selection:
 *
 *  1. Find the "ancestor builds": for each path back through the commit graph,
 *     the nearest commit that has a completed build. Builds on the same commit
 *     (rebuilds) win outright.
 *  2. For each snapshot key (story × browser × mode) take the snapshot from the
 *     preferred ancestor build. If it is approved it becomes the baseline;
 *     otherwise its own baseline carries forward (pending/denied changes do not
 *     move the baseline).
 *  3. When no ancestor builds exist (shallow history, squash merge, first build
 *     on a branch whose base was never built) fall back to the latest build on
 *     the same branch, then on the default branch.
 */
class BaselineResolver
{
    /** @return array{strategy: string, build_ids: list<int>} */
    public function ancestorBuilds(Build $build): array
    {
        $completed = Build::query()
            ->where('project_id', $build->project_id)
            ->when($build->exists, fn ($q) => $q->where('id', '<', $build->id))
            ->whereIn('status', array_map(fn (BuildStatus $s) => $s->value, array_filter(BuildStatus::cases(), fn ($s) => $s->isComplete())))
            ->orderByDesc('id')
            ->get(['id', 'commit_sha', 'branch', 'project_id']);

        if ($completed->isEmpty()) {
            return ['strategy' => 'none', 'build_ids' => []];
        }

        /** @var Collection<string, Build> $latestByCommit */
        $latestByCommit = $completed->groupBy('commit_sha')->map(fn (Collection $c) => $c->first());

        if ($same = $latestByCommit->get($build->commit_sha)) {
            return ['strategy' => 'rebuild', 'build_ids' => [$same->id]];
        }

        $found = $this->walk($build, $latestByCommit);

        // Squash/rebase merge: the merge commit's git ancestors don't include the PR's
        // builds, so the PR head build (where its changes were accepted) takes priority.
        $pr = PullRequest::where('project_id', $build->project_id)->where('merge_commit_sha', $build->commit_sha)->first();
        $head = $pr?->head_branch ? $completed->firstWhere('branch', $pr->head_branch) : null;
        if ($head && ! in_array($head->id, $found, true)) {
            return ['strategy' => $found ? 'ancestors+pull-request' : 'pull-request-head', 'build_ids' => [$head->id, ...$found]];
        }
        if ($found) {
            return ['strategy' => 'ancestors', 'build_ids' => $found];
        }

        $sameBranch = $completed->firstWhere('branch', $build->branch);
        if ($sameBranch) {
            return ['strategy' => 'branch', 'build_ids' => [$sameBranch->id]];
        }

        $default = $completed->firstWhere('branch', $build->project->default_branch);
        if ($default) {
            return ['strategy' => 'default-branch', 'build_ids' => [$default->id]];
        }

        return ['strategy' => 'none', 'build_ids' => []];
    }

    /**
     * BFS through the commit graph; stop each path at the first commit with a build.
     *
     * @param  Collection<string, Build>  $latestByCommit
     * @return list<int>
     */
    private function walk(Build $build, Collection $latestByCommit): array
    {
        $graph = $this->graph($build->project_id);
        $limit = (int) config('tintype.max_ancestor_commits', 1000);

        $queue = $graph[$build->commit_sha] ?? [];
        $seen = [];
        $hits = [];
        while ($queue && count($seen) < $limit) {
            $sha = array_shift($queue);
            if (isset($seen[$sha])) {
                continue;
            }
            $seen[$sha] = true;
            if ($hit = $latestByCommit->get($sha)) {
                $hits[$sha] = $hit;

                continue;
            }
            foreach ($graph[$sha] ?? [] as $parent) {
                $queue[] = $parent;
            }
        }

        // Drop hits that are themselves ancestors of another hit (reached via a longer path).
        $shas = array_keys($hits);
        $redundant = [];
        foreach ($shas as $sha) {
            $ancestors = $this->ancestorsOf($sha, $graph, $limit);
            foreach ($shas as $other) {
                if ($other !== $sha && isset($ancestors[$other])) {
                    $redundant[$other] = true;
                }
            }
        }

        // Preferred order: same branch first, then most recent.
        $kept = array_values(array_diff_key($hits, $redundant));
        usort($kept, fn (Build $a, Build $b) => [($b->branch === $build->branch), $b->id] <=> [($a->branch === $build->branch), $a->id]);

        return array_map(fn (Build $b) => $b->id, $kept);
    }

    /** @param array<string, list<string>> $graph
     * @return array<string, true> */
    private function ancestorsOf(string $sha, array $graph, int $limit): array
    {
        $out = [];
        $queue = $graph[$sha] ?? [];
        while ($queue && count($out) < $limit) {
            $s = array_shift($queue);
            if (isset($out[$s])) {
                continue;
            }
            $out[$s] = true;
            foreach ($graph[$s] ?? [] as $p) {
                $queue[] = $p;
            }
        }

        return $out;
    }

    /** @return array<string, list<string>> */
    private function graph(int $projectId): array
    {
        $graph = [];
        Commit::where('project_id', $projectId)->select(['sha', 'parents'])->orderBy('id')->chunk(5000, function ($chunk) use (&$graph) {
            foreach ($chunk as $c) {
                $graph[$c->sha] = $c->parents ?? [];
            }
        });

        return $graph;
    }

    /**
     * Resolve baselines for the given stories (DB ids) from the build's ancestor builds.
     *
     * @param  list<int>|null  $storyIds  null = all stories
     * @return array<string, Snapshot|null> key => approved baseline snapshot (null = new)
     */
    public function resolve(Build $build, ?array $storyIds = null): array
    {
        $ids = $build->baseline_build_ids ?? [];
        if (! $ids) {
            return [];
        }
        // baseline_build_ids is stored in priority order.
        $ancestors = Build::whereIn('id', $ids)->get()->sortBy(fn (Build $b) => array_search($b->id, $ids, true));

        $resolved = [];
        foreach ($ancestors as $ancestor) {
            $query = Snapshot::where('build_id', $ancestor->id)->with('baseline');
            if ($storyIds !== null) {
                $query->whereIn('story_id', $storyIds);
            }
            foreach ($query->get() as $snap) {
                $key = $snap->key();
                if (array_key_exists($key, $resolved)) {
                    continue;
                }
                $resolved[$key] = $this->baselineFrom($snap);
            }
        }

        return $resolved;
    }

    /** What a descendant should compare against, given an ancestor's snapshot. */
    public function baselineFrom(Snapshot $snap): ?Snapshot
    {
        if ($snap->isApproved()) {
            // Unchanged snapshots point at the approved image they matched; prefer the
            // newest row so review history stays linear, but the image is identical.
            return $snap;
        }

        return match ($snap->status) {
            SnapshotStatus::New, SnapshotStatus::Removed => null,
            default => $snap->baseline,
        };
    }

    /** True when capture-environment versions differ (forces a diff instead of a match). */
    public function captureEnvChanged(Build $build, ?Snapshot $baseline): bool
    {
        if (! $baseline) {
            return false;
        }
        $theirs = $baseline->relationLoaded('build') ? $baseline->build->capture_image : Build::whereKey($baseline->build_id)->value('capture_image');

        return $theirs !== null && $build->capture_image !== null && $this->major($theirs) !== $this->major($build->capture_image);
    }

    private function major(string $image): string
    {
        return preg_match('/:(\d+)/', $image, $m) ? $m[1] : $image;
    }
}
