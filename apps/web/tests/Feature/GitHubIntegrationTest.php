<?php

namespace Tests\Feature;

use App\Enums\BuildStatus;
use App\Enums\ReviewState;
use App\Enums\SnapshotStatus;
use App\Jobs\ReportBuildToGitHub;
use App\Models\AppSetting;
use App\Models\Build;
use App\Models\GithubInstallation;
use App\Models\PullRequest;
use App\Models\User;
use App\Services\GitHub\CheckRunReporter;
use App\Services\GitHub\GitHubApp;
use App\Services\UiReview;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\Tintype;
use Tests\TestCase;

class GitHubIntegrationTest extends TestCase
{
    use RefreshDatabase, Tintype;

    private function configureApp(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);
        AppSetting::put('github_app', ['id' => 123, 'slug' => 'tintype-test', 'name' => 'Tintype test', 'pem' => $pem, 'webhook_secret' => 'whsec']);
    }

    public function test_check_run_created_then_updated_with_conclusion(): void
    {
        $this->configureApp();
        $project = $this->makeProject(['github_repository' => 'acme/web']);
        Http::fake([
            'api.github.com/repos/acme/web/installation' => Http::response(['id' => 99]),
            'api.github.com/app/installations/99/access_tokens' => Http::response(['token' => 'inst-token']),
            'api.github.com/repos/acme/web/check-runs' => Http::response(['id' => 555], 201),
            'api.github.com/repos/acme/web/check-runs/555' => Http::response(['id' => 555]),
        ]);
        $build = $this->completedBuild($project, 'abc1234', 'main', ['x--y' => ['sha' => str_repeat('a', 64), 'status' => SnapshotStatus::Changed, 'review' => ReviewState::Pending]], BuildStatus::Capturing);

        app(CheckRunReporter::class)->report($build->fresh());
        $this->assertSame(555, $build->fresh()->github_check_run_id);
        $this->assertSame(99, $project->fresh()->github_installation_id);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/check-runs')
            && $r['status'] === 'in_progress' && $r['head_sha'] === 'abc1234' && $r->hasHeader('Authorization', 'token inst-token'));

        $build->update(['status' => BuildStatus::Pending, 'changed_count' => 1]);
        app(CheckRunReporter::class)->report($build->fresh());
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r['conclusion'] === 'action_required' && $r['output']['title'] === '1 change needs review');

        $build->update(['status' => BuildStatus::Accepted]);
        app(CheckRunReporter::class)->report($build->fresh());
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r['conclusion'] === 'success');
    }

    public function test_reporter_is_a_noop_without_app_or_repository(): void
    {
        Http::fake();
        $project = $this->makeProject();
        $build = $this->completedBuild($project, 'abc1234', 'main');
        app(CheckRunReporter::class)->report($build);
        Http::assertNothingSent();
    }

    public function test_webhook_signature_and_installation_events(): void
    {
        $this->configureApp();
        $body = json_encode(['action' => 'created', 'installation' => ['id' => 42, 'account' => ['login' => 'acme', 'type' => 'Organization']]]);
        $this->call('POST', '/github/webhook', [], [], [], ['HTTP_X_GITHUB_EVENT' => 'installation', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256=bad', 'CONTENT_TYPE' => 'application/json'], $body)->assertUnauthorized();
        $sig = 'sha256='.hash_hmac('sha256', $body, 'whsec');
        $this->call('POST', '/github/webhook', [], [], [], ['HTTP_X_GITHUB_EVENT' => 'installation', 'HTTP_X_HUB_SIGNATURE_256' => $sig, 'CONTENT_TYPE' => 'application/json'], $body)->assertOk();
        $this->assertSame('acme', GithubInstallation::where('installation_id', 42)->value('account_login'));
    }

    public function test_pull_request_webhook_tracks_pr_and_links_builds(): void
    {
        $this->configureApp();
        $project = $this->makeProject(['github_repository' => 'acme/web']);
        $build = $this->completedBuild($project, 'abc1234', 'feature/x');
        $body = json_encode([
            'action' => 'closed',
            'repository' => ['full_name' => 'acme/web'],
            'pull_request' => ['number' => 7, 'title' => 'Purple buttons', 'state' => 'closed', 'merged' => true, 'merge_commit_sha' => 'def5678',
                'head' => ['ref' => 'feature/x', 'sha' => 'abc1234'], 'base' => ['ref' => 'main'], 'user' => ['login' => 'dev'], 'html_url' => 'https://github.com/acme/web/pull/7'],
        ]);
        $sig = 'sha256='.hash_hmac('sha256', $body, 'whsec');
        $this->call('POST', '/github/webhook', [], [], [], ['HTTP_X_GITHUB_EVENT' => 'pull_request', 'HTTP_X_HUB_SIGNATURE_256' => $sig, 'CONTENT_TYPE' => 'application/json'], $body)->assertOk();
        $pr = PullRequest::where('project_id', $project->id)->where('number', 7)->firstOrFail();
        $this->assertSame(['merged', 'def5678', 'feature/x'], [$pr->state, $pr->merge_commit_sha, $pr->head_branch]);
        $this->assertSame(7, $build->fresh()->pr_number);
    }

    public function test_manifest_flow_stores_credentials(): void
    {
        Http::fake(['api.github.com/app-manifests/code123/conversions' => Http::response([
            'id' => 1, 'slug' => 'tintype-x', 'name' => 'Tintype X', 'pem' => 'PEM', 'webhook_secret' => 'sec', 'client_id' => 'cid', 'client_secret' => 'cs', 'owner' => ['login' => 'acme'],
        ], 201)]);
        $creds = app(GitHubApp::class)->completeManifest('code123');
        $this->assertSame('tintype-x', $creds['slug']);
        $this->assertSame('sec', AppSetting::get('github_app')['webhook_secret']);
        $this->assertNotSame('PEM', AppSetting::find('github_app')->getRawOriginal('value'), 'stored encrypted');
        $this->assertStringEndsWith('/apps/tintype-x/installations/new', app(GitHubApp::class)->installUrl());
        $manifest = app(GitHubApp::class)->manifest();
        $this->assertSame('write', $manifest['default_permissions']['checks']);
        // GitHub rejects a JSON array body ("[]") on this endpoint; it must be an object.
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/conversions') && $r->body() === '{}');
    }

    public function test_webhook_route_skips_request_forgery_protection(): void
    {
        // CSRF middleware is a no-op under unit tests, so assert the exclusion directly.
        $excluded = app('router')->getRoutes()->getByName('github.webhook')->excludedMiddleware();
        $this->assertContains(PreventRequestForgery::class, $excluded);
    }

    public function test_admin_callback_requires_state(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->get('/admin')->assertOk();
        $this->actingAs($admin)->get('/admin/github/callback?code=x&state=wrong')->assertForbidden();
    }

    public function test_finished_pr_build_also_posts_ui_review_check(): void
    {
        $this->configureApp();
        $project = $this->makeProject(['github_repository' => 'acme/web']);
        $this->commits($project, ['m1' => [], 'f1' => ['m1']]);
        $this->completedBuild($project, 'm1', 'main', ['a--b' => ['sha' => str_repeat('1', 64)]]);
        $head = $this->completedBuild($project, 'f1', 'feature', ['a--b' => ['sha' => str_repeat('2', 64), 'status' => SnapshotStatus::Changed, 'review' => ReviewState::Pending]], BuildStatus::Pending);
        $head->update(['pr_number' => 4, 'changed_count' => 1]);
        Http::fake([
            'api.github.com/repos/acme/web/installation' => Http::response(['id' => 9]),
            'api.github.com/app/installations/9/access_tokens' => Http::response(['token' => 't']),
            'api.github.com/repos/acme/web/check-runs' => Http::response(['id' => 78], 201),
        ]);
        (new ReportBuildToGitHub($head->id))->handle(app(CheckRunReporter::class));
        Http::assertSent(fn (Request $r) => ($r['name'] ?? null) === 'Visual Tests' && $r['conclusion'] === 'action_required');
        Http::assertSent(fn (Request $r) => ($r['name'] ?? null) === 'UI Review' && $r['conclusion'] === 'action_required' && $r['head_sha'] === 'f1');
        $this->assertDatabaseHas('pull_requests', ['project_id' => $project->id, 'number' => 4, 'base_branch' => 'main']);
    }

    public function test_new_push_with_different_ui_dismisses_approval(): void
    {
        $this->configureApp();
        $project = $this->makeProject(['github_repository' => 'acme/web']);
        $this->commits($project, ['m1' => [], 'f1' => ['m1'], 'f2' => ['f1'], 'f3' => ['f2']]);
        $this->completedBuild($project, 'm1', 'main', ['a--b' => ['sha' => str_repeat('1', 64)], 'c--d' => ['sha' => str_repeat('5', 64)]]);
        $changed = ['status' => SnapshotStatus::Changed, 'review' => ReviewState::Accepted];
        $this->completedBuild($project, 'f1', 'feature', ['a--b' => ['sha' => str_repeat('2', 64)] + $changed, 'c--d' => ['sha' => str_repeat('5', 64)]])->update(['pr_number' => 5]);
        $pr = PullRequest::create(['project_id' => $project->id, 'number' => 5, 'head_branch' => 'feature', 'base_branch' => 'main']);
        Http::fake([
            'api.github.com/repos/acme/web/installation' => Http::response(['id' => 9]),
            'api.github.com/app/installations/9/access_tokens' => Http::response(['token' => 't']),
            'api.github.com/repos/acme/web/check-runs' => Http::response(['id' => 79], 201),
        ]);
        $user = $this->member($project);
        $this->actingAs($user)->post(route('pulls.review', [$project->organization, $project, 5]), ['state' => 'approved'])->assertRedirect();
        $this->assertSame('approved', $pr->fresh()->review_status);

        // A push that leaves the UI as approved keeps the approval.
        $this->completedBuild($project, 'f2', 'feature', ['a--b' => ['sha' => str_repeat('2', 64)], 'c--d' => ['sha' => str_repeat('5', 64)]])->update(['pr_number' => 5]);
        app(CheckRunReporter::class)->reportPullRequest($pr->fresh()->load('project.organization'));
        $this->assertSame('approved', $pr->fresh()->review_status);

        // A push that changes another story dismisses it, and UI Review needs action again.
        $this->completedBuild($project, 'f3', 'feature', ['a--b' => ['sha' => str_repeat('2', 64)], 'c--d' => ['sha' => str_repeat('6', 64), 'status' => SnapshotStatus::Changed, 'review' => ReviewState::Pending]], BuildStatus::Pending)->update(['pr_number' => 5]);
        app(CheckRunReporter::class)->reportPullRequest($pr->fresh()->load('project.organization'));
        $this->assertSame('open', $pr->fresh()->review_status);
        $this->assertDatabaseHas('pull_request_reviews', ['pull_request_id' => $pr->id, 'state' => 'dismissed']);
        Http::assertSent(fn (Request $r) => ($r['name'] ?? null) === 'UI Review' && $r['head_sha'] === 'f3' && $r['conclusion'] === 'action_required' && $r['output']['title'] === '2 UI changes to review');
    }

    public function test_sub_threshold_match_is_not_a_ui_change(): void
    {
        $project = $this->makeProject();
        $this->commits($project, ['m1' => [], 'f1' => ['m1']]);
        $base = $this->completedBuild($project, 'm1', 'main', ['a--b' => ['sha' => str_repeat('1', 64)]]);
        $this->completedBuild($project, 'f1', 'feature', ['a--b' => ['sha' => str_repeat('2', 64), 'baseline' => $this->snap($base, 'a--b')->id]])->update(['pr_number' => 6]);
        $pr = PullRequest::create(['project_id' => $project->id, 'number' => 6, 'head_branch' => 'feature', 'base_branch' => 'main']);
        $this->assertSame([], app(UiReview::class)->compare($project, $pr, 6)['changes']);
    }

    public function test_ui_review_check(): void
    {
        $this->configureApp();
        $project = $this->makeProject(['github_repository' => 'acme/web']);
        $this->commits($project, ['m1' => [], 'f1' => ['m1']]);
        $this->completedBuild($project, 'm1', 'main', ['a--b' => ['sha' => str_repeat('1', 64)]]);
        $head = $this->completedBuild($project, 'f1', 'feature', ['a--b' => ['sha' => str_repeat('2', 64), 'status' => SnapshotStatus::Changed, 'review' => ReviewState::Accepted]]);
        $head->update(['pr_number' => 3]);
        $pr = PullRequest::create(['project_id' => $project->id, 'number' => 3, 'head_branch' => 'feature', 'base_branch' => 'main']);
        Http::fake([
            'api.github.com/repos/acme/web/installation' => Http::response(['id' => 9]),
            'api.github.com/app/installations/9/access_tokens' => Http::response(['token' => 't']),
            'api.github.com/repos/acme/web/check-runs' => Http::response(['id' => 77], 201),
        ]);
        app(CheckRunReporter::class)->reportPullRequest($pr->load('project.organization'));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/check-runs') && $r['name'] === 'UI Review' && $r['conclusion'] === 'action_required' && $r['head_sha'] === 'f1');

        $user = $this->member($project);
        $this->actingAs($user)->get(route('pulls.show', [$project->organization, $project, 3]))->assertOk()
            ->assertInertia(fn ($page) => $page->component('pulls/show')->has('changes', 1)->where('changes.0.kind', 'changed'));
        $this->actingAs($user)->post(route('pulls.review', [$project->organization, $project, 3]), ['state' => 'approved'])->assertRedirect();
        $this->assertSame('approved', $pr->fresh()->review_status);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/check-runs') && ($r['conclusion'] ?? null) === 'success');
        $this->assertInstanceOf(Build::class, $head);

        $other = $this->member($project);
        $this->actingAs($user)->post(route('pulls.assign', [$project->organization, $project, 3]), ['user_id' => $other->id])->assertRedirect();
        $this->get(route('pulls.show', [$project->organization, $project, 3]))->assertInertia(fn ($page) => $page->where('pull.assignees.0.id', $other->id));
        $this->post(route('pulls.assign', [$project->organization, $project, 3]), ['user_id' => $other->id, 'remove' => true]);
        $this->assertSame([], $pr->fresh()->assignees);
        $stranger = User::factory()->create();
        $this->post(route('pulls.assign', [$project->organization, $project, 3]), ['user_id' => $stranger->id])->assertStatus(422);
    }

    public function test_approve_and_accept_all_clears_pending_snapshots(): void
    {
        $project = $this->makeProject();
        $this->commits($project, ['m1' => [], 'f1' => ['m1']]);
        $this->completedBuild($project, 'm1', 'main', ['a--b' => ['sha' => str_repeat('1', 64)]]);
        $head = $this->completedBuild($project, 'f1', 'feature', [
            'a--b' => ['sha' => str_repeat('2', 64), 'status' => SnapshotStatus::Changed, 'review' => ReviewState::Pending],
            'a--c' => ['sha' => str_repeat('3', 64), 'status' => SnapshotStatus::New, 'review' => ReviewState::Pending],
        ], BuildStatus::Pending);
        $head->update(['pr_number' => 4]);
        PullRequest::create(['project_id' => $project->id, 'number' => 4, 'head_branch' => 'feature', 'base_branch' => 'main']);
        $user = $this->member($project);
        $show = route('pulls.show', [$project->organization, $project, 4]);
        $review = route('pulls.review', [$project->organization, $project, 4]);

        $this->actingAs($user)->get($show)->assertInertia(fn ($page) => $page->where('pendingInHead', 2));

        // Requesting changes never accepts anything, even if the flag is sent.
        $this->post($review, ['state' => 'changes_requested', 'accept_pending' => true])->assertRedirect();
        $this->assertSame(BuildStatus::Pending, $head->fresh()->status);

        $this->post($review, ['state' => 'approved', 'accept_pending' => true])->assertRedirect();
        $this->assertSame(0, $head->snapshots()->where('review_state', ReviewState::Pending->value)->count());
        $this->assertSame(BuildStatus::Accepted, $head->fresh()->status);
        $this->assertSame('approved', PullRequest::where('number', 4)->value('review_status'));
        $this->get($show)->assertInertia(fn ($page) => $page->where('pendingInHead', 0));
    }
}
