<?php

namespace Tests\Feature;

use App\Enums\BuildStatus;
use App\Enums\ReviewState;
use App\Enums\SnapshotStatus;
use App\Models\Build;
use App\Models\PullRequest;
use App\Models\Snapshot;
use App\Services\BaselineResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Tintype;
use Tests\TestCase;

class BaselineResolverTest extends TestCase
{
    use RefreshDatabase, Tintype;

    private function resolver(): BaselineResolver
    {
        return app(BaselineResolver::class);
    }

    private function newBuild($project, string $commit, string $branch): Build
    {
        $number = (int) Build::where('project_id', $project->id)->max('number') + 1;

        return Build::create(['project_id' => $project->id, 'number' => $number, 'commit_sha' => $commit, 'branch' => $branch, 'status' => BuildStatus::Capturing, 'browsers' => ['chromium']]);
    }

    private function ancestors(Build $build): array
    {
        $r = $this->resolver()->ancestorBuilds($build->load('project'));
        $build->update(['baseline_build_ids' => $r['build_ids'], 'baseline_strategy' => $r['strategy']]);

        return $r;
    }

    public function test_first_build_has_no_baseline(): void
    {
        $p = $this->makeProject();
        $this->commits($p, ['a1' => []]);
        $b = $this->newBuild($p, 'a1', 'main');
        $this->assertSame(['strategy' => 'none', 'build_ids' => []], $this->ancestors($b));
    }

    public function test_linear_history_uses_nearest_built_ancestor(): void
    {
        $p = $this->makeProject();
        $this->commits($p, ['a1' => [], 'a2' => ['a1'], 'a3' => ['a2'], 'a4' => ['a3']]);
        $b1 = $this->completedBuild($p, 'a1', 'main');
        $b2 = $this->completedBuild($p, 'a2', 'main');
        $b = $this->newBuild($p, 'a4', 'main'); // a3 has no build
        $this->assertSame(['strategy' => 'ancestors', 'build_ids' => [$b2->id]], $this->ancestors($b));
    }

    public function test_rebuild_of_same_commit_uses_previous_build(): void
    {
        $p = $this->makeProject();
        $this->commits($p, ['a1' => [], 'a2' => ['a1']]);
        $this->completedBuild($p, 'a1', 'main');
        $prev = $this->completedBuild($p, 'a2', 'main');
        $b = $this->newBuild($p, 'a2', 'main');
        $this->assertSame(['strategy' => 'rebuild', 'build_ids' => [$prev->id]], $this->ancestors($b));
    }

    public function test_in_progress_and_failed_builds_are_ignored(): void
    {
        $p = $this->makeProject();
        $this->commits($p, ['a1' => [], 'a2' => ['a1'], 'a3' => ['a2']]);
        $good = $this->completedBuild($p, 'a1', 'main');
        $this->completedBuild($p, 'a2', 'main', [], BuildStatus::Failed);
        $b = $this->newBuild($p, 'a3', 'main');
        $this->assertSame([$good->id], $this->ancestors($b)['build_ids']);
    }

    public function test_merge_commit_collects_both_parents_and_prefers_own_branch(): void
    {
        $p = $this->makeProject();
        // main: m1 - m2 ----- m3 (merge of f2)
        // feat:        \ f1 - f2
        $this->commits($p, ['m1' => [], 'm2' => ['m1'], 'f1' => ['m2'], 'f2' => ['f1'], 'm3' => ['m2', 'f2']]);
        $main = $this->completedBuild($p, 'm2', 'main');
        $feat = $this->completedBuild($p, 'f2', 'feature');
        $b = $this->newBuild($p, 'm3', 'main');
        $r = $this->ancestors($b);
        // m2 is an ancestor of f2 too, but via its own path it is the nearest on the first parent;
        // f2's build descends from m2, so m2's build is redundant.
        $this->assertSame('ancestors', $r['strategy']);
        $this->assertSame([$feat->id], $r['build_ids']);
    }

    public function test_parallel_branches_both_count_when_unrelated(): void
    {
        $p = $this->makeProject();
        $this->commits($p, ['r' => [], 'x1' => ['r'], 'y1' => ['r'], 'm' => ['x1', 'y1']]);
        $x = $this->completedBuild($p, 'x1', 'x');
        $y = $this->completedBuild($p, 'y1', 'main');
        $b = $this->newBuild($p, 'm', 'main');
        $this->assertSame([$y->id, $x->id], $this->ancestors($b)['build_ids']); // own branch first
    }

    public function test_falls_back_to_branch_then_default_branch_without_history(): void
    {
        $p = $this->makeProject();
        $main = $this->completedBuild($p, 'aaa1', 'main');
        $b = $this->newBuild($p, 'bbb1', 'feature'); // commit graph unknown (shallow clone)
        $this->assertSame(['strategy' => 'default-branch', 'build_ids' => [$main->id]], $this->ancestors($b));

        $feature = $this->completedBuild($p, 'bbb2', 'feature');
        $b2 = $this->newBuild($p, 'bbb3', 'feature');
        $this->assertSame(['strategy' => 'branch', 'build_ids' => [$feature->id]], $this->ancestors($b2));
    }

    public function test_squash_merge_prefers_pull_request_head_build(): void
    {
        $p = $this->makeProject();
        $this->commits($p, ['m1' => [], 'f1' => ['m1'], 'sq' => ['m1']]);
        $main = $this->completedBuild($p, 'm1', 'main');
        $head = $this->completedBuild($p, 'f1', 'feature');
        PullRequest::create(['project_id' => $p->id, 'number' => 7, 'head_branch' => 'feature', 'base_branch' => 'main', 'merge_commit_sha' => 'sq', 'state' => 'merged']);
        $b = $this->newBuild($p, 'sq', 'main');
        $this->assertSame(['strategy' => 'ancestors+pull-request', 'build_ids' => [$head->id, $main->id]], $this->ancestors($b));
    }

    public function test_resolve_uses_approved_snapshot_or_carries_previous_baseline(): void
    {
        $p = $this->makeProject();
        $this->commits($p, ['a1' => [], 'a2' => ['a1'], 'a3' => ['a2']]);
        $b1 = $this->completedBuild($p, 'a1', 'main', [
            'button--a' => ['sha' => str_repeat('1', 64), 'status' => SnapshotStatus::New, 'review' => ReviewState::Accepted],
            'button--b' => ['sha' => str_repeat('2', 64), 'status' => SnapshotStatus::New, 'review' => ReviewState::Accepted],
            'button--c' => ['sha' => str_repeat('3', 64), 'status' => SnapshotStatus::New, 'review' => ReviewState::Pending],
        ], BuildStatus::Pending);
        $a1 = $this->snap($b1, 'button--a');
        $bb1 = $this->snap($b1, 'button--b');
        $b2 = $this->completedBuild($p, 'a2', 'main', [
            'button--a' => ['sha' => str_repeat('4', 64), 'status' => SnapshotStatus::Changed, 'review' => ReviewState::Denied, 'baseline' => $a1->id],
            'button--b' => ['sha' => str_repeat('5', 64), 'status' => SnapshotStatus::Changed, 'review' => ReviewState::Accepted, 'baseline' => $bb1->id],
            'button--c' => ['sha' => str_repeat('3', 64), 'status' => SnapshotStatus::New, 'review' => ReviewState::Pending],
        ], BuildStatus::Pending, [$b1->id]);
        $b3 = $this->newBuild($p, 'a3', 'main');
        $this->ancestors($b3);

        $resolved = $this->resolver()->resolve($b3->fresh());
        $key = fn ($id) => Snapshot::makeKey($this->story($p, $id)->id, 'chromium', 'default');
        $this->assertSame($a1->id, $resolved[$key('button--a')]?->id, 'denied change keeps old baseline');
        $this->assertSame($this->snap($b2, 'button--b')->id, $resolved[$key('button--b')]?->id, 'accepted change becomes baseline');
        $this->assertNull($resolved[$key('button--c')], 'unreviewed new story stays new');
    }
}
