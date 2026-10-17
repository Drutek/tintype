<?php

namespace Tests\Feature;

use App\Enums\BuildStatus;
use App\Enums\Role;
use App\Models\Build;
use App\Models\CaptureJob;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Tintype;
use Tests\TestCase;

class BuildPipelineTest extends TestCase
{
    use RefreshDatabase, Tintype;

    private const WORKER = 'test-worker';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('tintype');
        config(['tintype.worker_token' => 'secret-worker', 'tintype.allow_any_arch' => false]);
    }

    private function cli(): array
    {
        return ['Authorization' => 'Bearer '.$this->projectToken, 'Accept' => 'application/json'];
    }

    private function worker(): array
    {
        return ['Authorization' => 'Bearer secret-worker', 'Accept' => 'application/json', 'X-Tintype-Arch' => 'x64'];
    }

    /** Run CLI flow up to queued capture jobs. */
    private function startBuild(string $commit, array $parents, string $branch, array $stories, array $options = []): Build
    {
        $res = $this->postJson('/api/v1/builds', [
            'commit' => $commit,
            'branch' => $branch,
            'commits' => [['sha' => $commit, 'parents' => $parents]],
            'cliVersion' => '0.1.0',
            'options' => $options,
        ], $this->cli())->assertCreated();
        $id = $res->json('id');
        $this->assertSame('PUT', $res->json('upload.method'));

        $this->call('PUT', "/api/v1/builds/{$id}/storybook", [], [], [], $this->transformHeadersToServerVars($this->cli() + ['Content-Type' => 'application/zip']), $this->storybookZip($stories))
            ->assertOk();
        $this->postJson("/api/v1/builds/{$id}/uploaded", [], $this->cli())->assertStatus(202);

        return Build::findOrFail($id);
    }

    /**
     * Act as a capture worker: lease every job, "capture" images from $images
     * (storyId => png bytes) and compare against baselines by hash.
     */
    private function captureAll(array $images, array $modes = []): void
    {
        while (true) {
            $lease = $this->postJson('/api/internal/v1/jobs/lease', ['workerId' => self::WORKER, 'browsers' => ['chromium'], 'image' => 'tintype/capture-chromium:1.0.0'], $this->worker());
            if ($lease->status() === 204) {
                return;
            }
            $job = $lease->assertOk()->json('job');
            $results = [];
            foreach ($job['stories'] as $story) {
                $id = $story['storyId'];
                foreach ($modes[$id] ?? ['default'] as $mode) {
                    if (($images[$id] ?? null) === 'error') {
                        $results[] = ['storyId' => $id, 'status' => 'error', 'modeKey' => $mode, 'error' => 'Play function failed: boom'];

                        continue;
                    }
                    if (($images[$id] ?? null) === 'skip') {
                        $results[] = ['storyId' => $id, 'status' => 'skipped', 'modeKey' => $mode];

                        continue;
                    }
                    $png = $images[$id];
                    $sha = hash('sha256', $png);
                    $missing = $this->postJson('/api/internal/v1/images/missing', ['shas' => [$sha]], $this->worker())->json('missing');
                    if ($missing) {
                        $this->call('PUT', "/api/internal/v1/images/{$sha}", [], [], [], $this->transformHeadersToServerVars($this->worker() + ['Content-Type' => 'image/png']), $png)->assertCreated();
                    }
                    $baseline = ((array) $story['baselines'])[$mode] ?? null;
                    $results[] = [
                        'storyId' => $id, 'status' => 'captured', 'modeKey' => $mode, 'modeName' => $mode,
                        'imageSha' => $sha, 'width' => 10, 'height' => 10,
                        'baselineSnapshotId' => $baseline['snapshotId'] ?? null,
                        'comparison' => $baseline ? ($baseline['sha'] === $sha ? 'unchanged' : 'changed') : 'new',
                        'diffRatio' => 0.5,
                    ];
                }
            }
            $this->postJson("/api/internal/v1/jobs/{$job['id']}/results", ['workerId' => self::WORKER, 'results' => $results, 'complete' => true], $this->worker())->assertOk();
        }
    }

    private function buildStatus(Build $build): array
    {
        return $this->getJson("/api/v1/builds/{$build->id}", $this->cli())->assertOk()->json();
    }

    public function test_full_lifecycle_new_accept_change_review(): void
    {
        $project = $this->makeProject();
        $red = $this->png(10, 10, [255, 0, 0]);
        $blue = $this->png(10, 10, [0, 0, 255]);
        $green = $this->png(10, 10, [0, 255, 0]);

        // Build 1 (first build) is auto-accepted.
        $b1 = $this->startBuild('aaaaaaa1', [], 'main', ['button--primary', 'button--secondary']);
        $this->assertSame(BuildStatus::Queued, $b1->fresh()->status);
        $this->assertSame(2, $b1->fresh()->story_count);
        $this->captureAll(['button--primary' => $red, 'button--secondary' => $blue]);
        $s1 = $this->buildStatus($b1);
        $this->assertSame('accepted', $s1['status']);
        $this->assertSame('first-build', $s1['autoAccepted']);
        $this->assertSame(2, $s1['counts']['new']);
        $this->assertSame(0, $s1['exitCode']);

        // Build 2 on a feature branch: one change, one unchanged.
        $b2 = $this->startBuild('bbbbbbb2', ['aaaaaaa1'], 'feature', ['button--primary', 'button--secondary']);
        $this->assertSame('ancestors', $b2->fresh()->baseline_strategy);
        $this->captureAll(['button--primary' => $green, 'button--secondary' => $blue]);
        $s2 = $this->buildStatus($b2);
        $this->assertSame('pending', $s2['status']);
        $this->assertSame(1, $s2['counts']['changed']);
        $this->assertSame(1, $s2['counts']['unchanged']);
        $this->assertSame(1, $s2['exitCode']);
        $this->assertSame('button--primary', $s2['changes'][0]['storyId']);

        // Review in the UI.
        $user = $this->member($project, Role::Reviewer);
        $changed = $b2->snapshots()->where('status', 'changed')->first();
        $this->actingAs($user)
            ->post(route('builds.review', [$project->organization, $project, $b2->number]), ['decision' => 'accepted', 'snapshotIds' => [$changed->id]])
            ->assertRedirect();
        $this->assertSame('accepted', $this->buildStatus($b2)['status']);

        // Build 3 on the feature branch: green is now the baseline -> passes.
        $b3 = $this->startBuild('ccccccc3', ['bbbbbbb2'], 'feature', ['button--primary', 'button--secondary']);
        $this->captureAll(['button--primary' => $green, 'button--secondary' => $blue]);
        $this->assertSame('passed', $this->buildStatus($b3)['status']);

        // Build 4 on main from the first commit still compares against red (main lineage).
        $b4 = $this->startBuild('ddddddd4', ['aaaaaaa1'], 'main', ['button--primary', 'button--secondary']);
        $this->captureAll(['button--primary' => $red, 'button--secondary' => $blue]);
        $this->assertSame('passed', $this->buildStatus($b4)['status']);
    }

    public function test_denied_changes_and_errors(): void
    {
        $project = $this->makeProject();
        $red = $this->png(10, 10, [255, 0, 0]);
        $b1 = $this->startBuild('aaaaaaa1', [], 'main', ['card--a', 'card--b']);
        $this->captureAll(['card--a' => $red, 'card--b' => $red]);

        $b2 = $this->startBuild('bbbbbbb2', ['aaaaaaa1'], 'main', ['card--a', 'card--b']);
        $this->captureAll(['card--a' => $this->png(10, 10, [1, 2, 3]), 'card--b' => 'error']);
        $s = $this->buildStatus($b2);
        $this->assertSame('broken', $s['status']);
        $this->assertSame(2, $s['exitCode']);
        $this->assertSame(1, $s['counts']['errors']);

        $user = $this->member($project);
        $this->actingAs($user)->post(route('builds.review', [$project->organization, $project, $b2->number]), ['decision' => 'denied', 'scope' => 'all']);
        $this->assertSame('broken', $this->buildStatus($b2)['status'], 'errors dominate');
        $this->assertSame('denied', $b2->snapshots()->where('status', 'changed')->first()->review_state->value);

        // Next build: denied change did not move the baseline.
        $b3 = $this->startBuild('ccccccc3', ['bbbbbbb2'], 'main', ['card--a', 'card--b']);
        $this->captureAll(['card--a' => $red, 'card--b' => $red]);
        $this->assertSame('passed', $this->buildStatus($b3)['status']);
    }

    public function test_only_story_names_inherits_uncaptured_and_removed_stories(): void
    {
        $this->makeProject();
        $red = $this->png(10, 10, [255, 0, 0]);
        $this->startBuild('aaaaaaa1', [], 'main', ['a--one', 'a--two', 'a--three']);
        $this->captureAll(['a--one' => $red, 'a--two' => $red, 'a--three' => $red]);

        // a--three deleted from the Storybook; only a--one captured.
        $b2 = $this->startBuild('bbbbbbb2', ['aaaaaaa1'], 'main', ['a--one', 'a--two'], ['onlyStoryNames' => ['a--one']]);
        $this->assertSame(1, CaptureJob::where('build_id', $b2->id)->count());
        $this->captureAll(['a--one' => $red]);
        $s = $this->buildStatus($b2);
        $this->assertSame('passed', $s['status']);
        $this->assertSame(2, $s['counts']['unchanged']);
        $this->assertSame(1, $s['counts']['removed']);
        $this->assertTrue((bool) $this->snap($b2, 'a--two')->params['inherited']);
    }

    public function test_modes_and_auto_accept_changes(): void
    {
        $this->makeProject();
        $red = $this->png(10, 10, [255, 0, 0]);
        $this->startBuild('aaaaaaa1', [], 'main', ['x--y']);
        $this->captureAll(['x--y' => $red], ['x--y' => ['m1', 'm2']]);
        $b2 = $this->startBuild('bbbbbbb2', ['aaaaaaa1'], 'main', ['x--y'], ['autoAcceptChanges' => true]);
        $this->captureAll(['x--y' => $this->png(10, 10, [9, 9, 9])], ['x--y' => ['m1', 'm2']]);
        $s = $this->buildStatus($b2);
        $this->assertSame('accepted', $s['status']);
        $this->assertSame(2, $s['counts']['changed']);
        $this->assertSame('cli', $s['autoAccepted']);
    }

    public function test_worker_auth_arch_and_lease_expiry(): void
    {
        $this->makeProject();
        $this->postJson('/api/internal/v1/jobs/lease', ['workerId' => 'w'], ['Authorization' => 'Bearer nope'])->assertUnauthorized();
        $this->postJson('/api/internal/v1/jobs/lease', ['workerId' => 'w'], ['Authorization' => 'Bearer secret-worker', 'X-Tintype-Arch' => 'arm64'])->assertForbidden();

        $b = $this->startBuild('aaaaaaa1', [], 'main', ['a--b']);
        $job = $this->postJson('/api/internal/v1/jobs/lease', ['workerId' => 'w1'], $this->worker())->assertOk()->json('job');
        $this->assertSame(1, $job['attempt']);
        $this->assertStringContainsString("/storybooks/{$b->id}/", $job['storybookUrl']);

        // Storybook files are served at the tokenised URL.
        $this->get(parse_url($job['storybookUrl'], PHP_URL_PATH).'iframe.html')->assertOk()->assertHeader('Content-Type', 'text/html; charset=utf-8');
        $this->get("/storybooks/{$b->id}/wrongtoken/iframe.html")->assertNotFound();
        $this->assertStringEndsWith("/api/internal/v1/builds/{$b->id}/storybook.zip", $job['storybookArchiveUrl']);
        $this->get(parse_url($job['storybookArchiveUrl'], PHP_URL_PATH), $this->worker())->assertOk()->assertHeader('Content-Type', 'application/zip');
        $this->get(parse_url($job['storybookArchiveUrl'], PHP_URL_PATH))->assertUnauthorized();

        // Lease expires -> another worker gets it; the first worker's results are rejected.
        CaptureJob::whereKey($job['id'])->update(['lease_expires_at' => now()->subMinute()]);
        $job2 = $this->postJson('/api/internal/v1/jobs/lease', ['workerId' => 'w2'], $this->worker())->assertOk()->json('job');
        $this->assertSame($job['id'], $job2['id']);
        $this->assertSame(2, $job2['attempt']);
        $this->postJson("/api/internal/v1/jobs/{$job['id']}/results", ['workerId' => 'w1', 'results' => [], 'complete' => true], $this->worker())->assertStatus(409);

        // Exhausted attempts fail the shard and finish the build as failed.
        CaptureJob::whereKey($job['id'])->update(['lease_expires_at' => now()->subMinute(), 'attempts' => 3]);
        $this->postJson('/api/internal/v1/jobs/lease', ['workerId' => 'w3'], $this->worker())->assertNoContent();
        $this->assertSame(BuildStatus::Failed, $b->fresh()->status);
    }

    public function test_invalid_uploads_fail_the_build(): void
    {
        $this->makeProject();
        $id = $this->postJson('/api/v1/builds', ['commit' => 'abcdef1', 'branch' => 'main'], $this->cli())->json('id');
        $this->call('PUT', "/api/v1/builds/{$id}/storybook", [], [], [], $this->transformHeadersToServerVars($this->cli()), 'not a zip');
        $this->postJson("/api/v1/builds/{$id}/uploaded", [], $this->cli())->assertStatus(202);
        $s = $this->getJson("/api/v1/builds/{$id}", $this->cli())->json();
        $this->assertSame('failed', $s['status']);
        $this->assertSame(2, $s['exitCode']);
        $this->getJson('/api/v1/builds/'.$id, ['Authorization' => 'Bearer tt_bad'])->assertUnauthorized();
    }

    public function test_other_projects_cannot_read_builds(): void
    {
        $this->makeProject();
        $id = $this->postJson('/api/v1/builds', ['commit' => 'abcdef1', 'branch' => 'main'], $this->cli())->json('id');
        $other = $this->makeProject();
        $this->getJson("/api/v1/builds/{$id}", $this->cli())->assertNotFound();
        $this->assertInstanceOf(Project::class, $other);
    }

    public function test_baseline_commits_for_turbosnap(): void
    {
        $this->makeProject();
        $red = $this->png(10, 10, [255, 0, 0]);
        $this->postJson('/api/v1/baseline-commits', ['commit' => 'aaaaaaa1', 'branch' => 'main'], $this->cli())->assertOk()->assertJson(['commits' => []]);
        $this->startBuild('aaaaaaa1', [], 'main', ['a--b']);
        $this->captureAll(['a--b' => $red]);
        $this->postJson('/api/v1/baseline-commits', ['commit' => 'bbbbbbb2', 'branch' => 'feat', 'commits' => [['sha' => 'bbbbbbb2', 'parents' => ['aaaaaaa1']]]], $this->cli())
            ->assertOk()->assertJson(['strategy' => 'ancestors', 'commits' => ['aaaaaaa1']]);

        // TurboSnap: only the affected story file is captured; the other is inherited.
        $b2 = $this->startBuild('bbbbbbb2', ['aaaaaaa1'], 'feat', ['a--b', 'c--d'], ['turboSnap' => ['affectedImportPaths' => ['./src/c.stories.tsx']]]);
        $this->assertSame([['c--d']], CaptureJob::where('build_id', $b2->id)->pluck('story_ids')->all());
    }

    public function test_stories_without_reported_results_become_errors(): void
    {
        $this->makeProject();
        $b = $this->startBuild('aaaaaaa1', [], 'main', ['a--one', 'a--two']);
        $job = $this->postJson('/api/internal/v1/jobs/lease', ['workerId' => 'w'], $this->worker())->json('job');
        $png = $this->png(4, 4, [1, 2, 3]);
        $sha = hash('sha256', $png);
        $this->call('PUT', "/api/internal/v1/images/{$sha}", [], [], [], $this->transformHeadersToServerVars($this->worker()), $png);
        // Worker only reports one of the two stories.
        $this->postJson("/api/internal/v1/jobs/{$job['id']}/results", ['workerId' => 'w', 'complete' => true, 'results' => [
            ['storyId' => 'a--one', 'status' => 'captured', 'modeKey' => 'default', 'imageSha' => $sha],
        ]], $this->worker())->assertOk();
        $s = $this->buildStatus($b);
        $this->assertSame('broken', $s['status']);
        $this->assertSame(1, $s['counts']['errors']);
        $this->assertStringContainsString('did not report', $this->snap($b, 'a--two')->error);
    }
}
