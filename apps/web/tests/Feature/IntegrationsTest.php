<?php

namespace Tests\Feature;

use App\Enums\BuildStatus;
use App\Enums\ReviewState;
use App\Enums\SnapshotStatus;
use App\Jobs\ReportBuildToScm;
use App\Jobs\SendBuildNotifications;
use App\Models\Build;
use App\Models\Image;
use App\Models\Snapshot;
use App\Services\BuildFinalizer;
use App\Services\ImageStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Tintype;
use Tests\TestCase;

class IntegrationsTest extends TestCase
{
    use RefreshDatabase, Tintype;

    public function test_webhook_and_slack_notifications_fire_once_per_status(): void
    {
        $project = $this->makeProject(['settings' => ['notify_webhook_url' => 'https://hooks.example.com/t', 'notify_slack_webhook_url' => 'https://hooks.slack.com/x']]);
        $build = $this->completedBuild($project, 'abc1234', 'main', [], BuildStatus::Passed);
        Http::fake();

        (new SendBuildNotifications($build->id))->handle();
        (new SendBuildNotifications($build->id))->handle(); // same status: no resend
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://hooks.example.com/t'
            && $r->hasHeader('X-Tintype-Signature', 'sha256='.hash_hmac('sha256', $r->body(), $project->fresh()->token_hash))
            && $r['build']['status'] === 'passed');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'slack') && str_contains($r['text'], ':white_check_mark:'));

        $build->update(['status' => BuildStatus::Denied]);
        (new SendBuildNotifications($build->id))->handle();
        Http::assertSentCount(4);
    }

    public function test_gitlab_and_bitbucket_commit_statuses(): void
    {
        Http::fake();
        $gl = $this->makeProject(['settings' => ['scm' => 'gitlab', 'scm_repository' => 'group/web']]);
        $gl->setScmToken('glpat-123');
        $gl->save();
        $b = $this->completedBuild($gl, 'abc1234', 'feature/x', [], BuildStatus::Pending);
        $b->update(['changed_count' => 2]);
        app()->call([new ReportBuildToScm($b->id), 'handle']);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://gitlab.com/api/v4/projects/group%2Fweb/statuses/abc1234'
            && $r['state'] === 'pending' && $r['description'] === '2 changes need review' && $r->hasHeader('Authorization', 'Bearer glpat-123'));

        $bb = $this->makeProject(['settings' => ['scm' => 'bitbucket', 'scm_repository' => 'acme/web']]);
        $bb->setScmToken('bbtoken');
        $bb->save();
        $b2 = $this->completedBuild($bb, 'def5678', 'main', [], BuildStatus::Passed);
        app()->call([new ReportBuildToScm($b2->id), 'handle']);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.bitbucket.org/2.0/repositories/acme/web/commit/def5678/statuses/build' && $r['state'] === 'SUCCESSFUL');

        $this->assertStringNotContainsString('bbtoken', json_encode($bb->fresh()->settings));
        $this->assertArrayNotHasKey('scm_token_encrypted', $bb->fresh()->publicSettings());
    }

    public function test_rebaseline_accepts_next_default_branch_build(): void
    {
        $project = $this->makeProject();
        $owner = $this->member($project);
        $this->actingAs($owner)->post(route('projects.rebaseline', [$project->organization, $project]))->assertRedirect();
        $this->assertTrue($project->fresh()->setting('rebaseline_pending'));

        $prior = $this->completedBuild($project, 'aaa1111', 'main', ['a--b' => ['sha' => str_repeat('1', 64)]]);
        $build = Build::create(['project_id' => $project->id, 'number' => 2, 'commit_sha' => 'bbb2222', 'branch' => 'main', 'status' => BuildStatus::Capturing, 'browsers' => ['chromium'], 'baseline_build_ids' => [$prior->id], 'baseline_strategy' => 'ancestors']);
        Snapshot::create(['build_id' => $build->id, 'story_id' => $this->story($project, 'a--b')->id, 'browser' => 'chromium', 'mode_key' => 'default', 'status' => SnapshotStatus::Changed, 'review_state' => ReviewState::Pending, 'image_sha' => str_repeat('2', 64)]);
        app(BuildFinalizer::class)->finalize($build->load('project'));

        $this->assertSame(BuildStatus::Accepted, $build->fresh()->status);
        $this->assertSame('rebaseline', $build->fresh()->options['autoAccepted']);
        $this->assertFalse($project->fresh()->setting('rebaseline_pending'));
        $this->assertFalse((bool) $project->fresh()->setting('scm_token_encrypted'));
    }

    public function test_capture_env_change_is_flagged(): void
    {
        Storage::fake('tintype');
        config(['tintype.worker_token' => 'w']);
        $project = $this->makeProject();
        $store = app(ImageStore::class);
        $old = $store->put($this->png(4, 4, [0, 0, 0]));
        $new = $store->put($this->png(4, 4, [9, 9, 9]));
        $prior = $this->completedBuild($project, 'aaa1111', 'main', ['a--b' => ['sha' => $old->sha256]]);
        $prior->update(['capture_image' => 'tintype/capture-chromium:1.2.0']);
        $build = Build::create(['project_id' => $project->id, 'number' => 2, 'commit_sha' => 'bbb2222', 'branch' => 'main', 'status' => BuildStatus::Queued, 'browsers' => ['chromium'], 'baseline_build_ids' => [$prior->id], 'baseline_strategy' => 'ancestors']);
        $job = $build->captureJobs()->create(['browser' => 'chromium', 'story_ids' => ['a--b']]);
        $h = ['Authorization' => 'Bearer w', 'X-Tintype-Arch' => 'x64'];
        $lease = $this->postJson('/api/internal/v1/jobs/lease', ['workerId' => 'w1', 'image' => 'tintype/capture-chromium:2.0.0'], $h)->json('job');
        $baseline = $lease['stories'][0]['baselines']['default'];
        $this->postJson("/api/internal/v1/jobs/{$job->id}/results", ['workerId' => 'w1', 'complete' => true, 'results' => [[
            'storyId' => 'a--b', 'status' => 'captured', 'modeKey' => 'default', 'imageSha' => $new->sha256,
            'baselineSnapshotId' => $baseline['snapshotId'], 'comparison' => 'unchanged',
        ]]], $h)->assertOk();
        $snap = Snapshot::where('build_id', $build->id)->firstOrFail();
        $this->assertSame(SnapshotStatus::Changed, $snap->status, 'major version change forces a diff even if within threshold');
        $this->assertTrue($snap->params['captureEnvChanged']);
    }

    public function test_garbage_collection_keeps_baselines_and_removes_orphans(): void
    {
        Storage::fake('tintype');
        $project = $this->makeProject(['settings' => ['retention_days' => 30]]);
        $store = app(ImageStore::class);
        $keepImg = $store->put($this->png(2, 2, [1, 1, 1]));
        $dropImg = $store->put($this->png(2, 2, [2, 2, 2]));
        $orphan = $store->put($this->png(2, 2, [3, 3, 3]));

        $old = $this->completedBuild($project, 'aaa1111', 'main', ['a--b' => ['sha' => $keepImg->sha256]]);
        $stale = $this->completedBuild($project, 'bbb2222', 'feature', ['a--b' => ['sha' => $dropImg->sha256, 'status' => SnapshotStatus::Changed, 'review' => ReviewState::Denied]]);
        $this->completedBuild($project, 'ccc3333', 'feature', ['a--b' => ['sha' => $keepImg->sha256, 'baseline' => $this->snap($old, 'a--b')->id]]);
        $this->completedBuild($project, 'ddd4444', 'main', ['a--b' => ['sha' => $keepImg->sha256, 'baseline' => $this->snap($old, 'a--b')->id]]);
        Build::whereIn('id', [$old->id, $stale->id])->update(['created_at' => now()->subDays(60)]);
        Image::query()->update(['created_at' => now()->subDays(2)]);

        $this->artisan('tintype:gc')->assertSuccessful();
        $this->assertNotNull(Build::find($old->id), 'referenced as a baseline');
        $this->assertNull(Build::find($stale->id), 'expired and unreferenced');
        $this->assertTrue(Image::whereKey($keepImg->sha256)->exists());
        $this->assertFalse(Image::whereKey($dropImg->sha256)->exists());
        $this->assertFalse(Image::whereKey($orphan->sha256)->exists());
        Storage::disk('tintype')->assertMissing(Image::pathFor($orphan->sha256));
    }

    public function test_a11y_violations_are_stored_and_new_ones_counted(): void
    {
        Storage::fake('tintype');
        config(['tintype.worker_token' => 'w']);
        $project = $this->makeProject();
        $img = app(ImageStore::class)->put($this->png(4, 4, [0, 0, 0]));
        $prior = $this->completedBuild($project, 'aaa1111', 'main', ['a--b' => ['sha' => $img->sha256]]);
        Snapshot::where('build_id', $prior->id)->update(['a11y' => json_encode([['id' => 'image-alt', 'impact' => 'critical', 'help' => 'x', 'helpUrl' => 'u', 'nodes' => 1, 'targets' => ['img']]])]);
        $build = Build::create(['project_id' => $project->id, 'number' => 2, 'commit_sha' => 'bbb2222', 'branch' => 'main', 'status' => BuildStatus::Queued, 'browsers' => ['chromium'], 'baseline_build_ids' => [$prior->id], 'baseline_strategy' => 'ancestors']);
        $job = $build->captureJobs()->create(['browser' => 'chromium', 'story_ids' => ['a--b']]);
        $h = ['Authorization' => 'Bearer w', 'X-Tintype-Arch' => 'x64'];
        $lease = $this->postJson('/api/internal/v1/jobs/lease', ['workerId' => 'w1'], $h)->json('job');
        $this->assertTrue($lease['settings']['a11y']);
        $this->postJson("/api/internal/v1/jobs/{$job->id}/results", ['workerId' => 'w1', 'complete' => true, 'results' => [[
            'storyId' => 'a--b', 'status' => 'captured', 'modeKey' => 'default', 'imageSha' => $img->sha256,
            'baselineSnapshotId' => $lease['stories'][0]['baselines']['default']['snapshotId'], 'comparison' => 'unchanged',
            'a11y' => [
                ['id' => 'image-alt', 'impact' => 'critical', 'help' => 'Images must have alt text', 'helpUrl' => 'u', 'nodes' => 1, 'targets' => ['img']],
                ['id' => 'color-contrast', 'impact' => 'serious', 'help' => 'Contrast', 'helpUrl' => 'u', 'nodes' => 2, 'targets' => ['button']],
            ],
        ]]], $h)->assertOk();
        $b = $build->fresh();
        $this->assertSame([2, 1, 'passed'], [$b->a11y_count, $b->a11y_new_count, $b->status->value]);
        $this->getJson("/api/v1/builds/{$build->id}", ['Authorization' => 'Bearer '.$this->projectToken])->assertJsonPath('counts.a11yNew', 1);
    }
}
