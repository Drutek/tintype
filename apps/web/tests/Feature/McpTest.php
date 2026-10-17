<?php

namespace Tests\Feature;

use App\Enums\BuildStatus;
use App\Enums\ReviewState;
use App\Enums\Role;
use App\Enums\SnapshotStatus;
use App\Mcp\Servers\TintypeServer;
use App\Mcp\Tools\CommentOnBuild;
use App\Mcp\Tools\GetBuild;
use App\Mcp\Tools\GetPullRequest;
use App\Mcp\Tools\GetSnapshot;
use App\Mcp\Tools\ListBuilds;
use App\Mcp\Tools\ListProjects;
use App\Mcp\Tools\ReviewPullRequest;
use App\Mcp\Tools\ReviewSnapshots;
use App\Models\ApiToken;
use App\Models\Build;
use App\Models\Project;
use App\Models\PullRequest;
use App\Models\User;
use App\Services\ImageStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Tintype;
use Tests\TestCase;

class McpTest extends TestCase
{
    use RefreshDatabase, Tintype;

    private Project $project;

    private Build $head;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('tintype');
        $store = app(ImageStore::class);
        $red = $store->put($this->png(4, 4, [255, 0, 0]))->sha256;
        $blue = $store->put($this->png(4, 4, [0, 0, 255]))->sha256;
        $this->project = $this->makeProject(['slug' => 'ds']);
        $this->project->save();
        $this->commits($this->project, ['m1' => [], 'f1' => ['m1']]);
        $base = $this->completedBuild($this->project, 'm1', 'main', ['button--primary' => ['sha' => $red, 'review' => ReviewState::Accepted, 'status' => SnapshotStatus::New]], BuildStatus::Accepted);
        $this->head = $this->completedBuild($this->project, 'f1', 'feature', [
            'button--primary' => ['sha' => $blue, 'status' => SnapshotStatus::Changed, 'review' => ReviewState::Pending, 'baseline' => $this->snap($base, 'button--primary')->id],
        ], BuildStatus::Pending);
        $this->head->update(['pr_number' => 7, 'changed_count' => 1]);
        PullRequest::create(['project_id' => $this->project->id, 'number' => 7, 'head_branch' => 'feature', 'base_branch' => 'main', 'title' => 'Blue button']);
    }

    private function ref(): string
    {
        return $this->project->organization->slug.'/ds';
    }

    public function test_token_settings_issue_show_once_and_revoke(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('tokens.store'), ['name' => 'Laptop'])->assertRedirect();
        $plain = session('newToken');
        $this->assertMatchesRegularExpression('/^tu_[a-z0-9]{8}_\w{40}$/', $plain);
        $this->withoutExceptionHandling()->get(route('tokens.index'))->assertInertia(fn ($page) => $page->component('settings/tokens')->where('tokens.0.name', 'Laptop')->missing('tokens.0.token_hash'));
        $this->assertSame($user->id, ApiToken::findByToken($plain)?->user_id);
        $this->assertNull(ApiToken::findByToken($plain.'x'));

        $other = User::factory()->create();
        $token = ApiToken::first();
        $this->withExceptionHandling()->actingAs($other)->delete(route('tokens.destroy', $token))->assertNotFound();
        $this->actingAs($user)->delete(route('tokens.destroy', $token))->assertRedirect();
        $this->assertNull(ApiToken::findByToken($plain));
    }

    public function test_http_endpoint_requires_a_personal_token(): void
    {
        $body = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => (object) []];
        $this->postJson('/mcp', $body)->assertUnauthorized()->assertHeader('WWW-Authenticate');
        $this->postJson('/mcp', $body, ['Authorization' => 'Bearer '.$this->projectToken])->assertUnauthorized();

        [, $plain] = ApiToken::issue($this->member($this->project, Role::Viewer), 'test');
        $res = $this->postJson('/mcp', $body, ['Authorization' => "Bearer {$plain}"])->assertOk();
        $names = collect($res->json('result.tools'))->pluck('name');
        $this->assertContains('get-build', $names);
        $this->assertContains('review-snapshots', $names);
        $this->assertNotNull(ApiToken::first()->last_used_at);
    }

    public function test_oauth_flow_from_discovery_to_tool_call(): void
    {
        $body = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => (object) []];
        // 1. Unauthenticated request points the client at the resource metadata.
        $challenge = $this->postJson('/mcp', $body)->assertUnauthorized()->headers->get('WWW-Authenticate');
        $this->assertStringContainsString('resource_metadata="'.url('/.well-known/oauth-protected-resource/mcp').'"', $challenge);
        $this->getJson('/.well-known/oauth-protected-resource/mcp')->assertOk()->assertJsonPath('authorization_servers.0', url('/'));
        $meta = $this->getJson('/.well-known/oauth-authorization-server')->assertOk()->json();
        $this->assertSame(url('/oauth/register'), $meta['registration_endpoint']);

        // 2. Dynamic client registration (as Claude Code does, with a localhost callback).
        $redirect = 'http://localhost:33418/callback';
        $clientId = $this->postJson('/oauth/register', ['client_name' => 'Claude Code', 'redirect_uris' => [$redirect]])->assertCreated()->json('client_id');

        // 3. The user signs in and approves in the browser (PKCE, public client).
        $verifier = str_repeat('v', 64);
        $query = http_build_query([
            'client_id' => $clientId, 'redirect_uri' => $redirect, 'response_type' => 'code', 'scope' => 'mcp:use', 'state' => 'xyz',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='), 'code_challenge_method' => 'S256',
        ]);
        $this->get('/oauth/authorize?'.$query)->assertRedirect(route('login'));
        $user = $this->member($this->project, Role::Reviewer);
        $page = $this->actingAs($user)->get('/oauth/authorize?'.$query)->assertOk()->assertSee('Authorize Claude Code')->assertSee('localhost');
        preg_match('/name="auth_token" value="([^"]+)"/', $page->getContent(), $m);
        $location = $this->post('/oauth/authorize', ['state' => 'xyz', 'client_id' => $clientId, 'auth_token' => $m[1]])->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith($redirect, $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $params);
        $this->assertSame('xyz', $params['state']);

        // 4. Code for token, then a tool call as that user.
        $token = $this->postJson('/oauth/token', [
            'grant_type' => 'authorization_code', 'client_id' => $clientId, 'redirect_uri' => $redirect, 'code' => $params['code'], 'code_verifier' => $verifier,
        ])->assertOk()->json();
        $this->assertNotEmpty($token['refresh_token']);
        app('auth')->forgetGuards();
        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'list-projects', 'arguments' => (object) []]], ['Authorization' => 'Bearer '.$token['access_token']])
            ->assertOk()->assertJsonPath('result.structuredContent.organizations.0.projects.0.project', $this->ref())
            ->assertJsonPath('result.structuredContent.organizations.0.role', 'reviewer');
    }

    public function test_read_tools_only_show_projects_you_belong_to(): void
    {
        $viewer = $this->member($this->project, Role::Viewer);
        TintypeServer::actingAs($viewer)->tool(ListProjects::class)->assertOk()->assertSee($this->ref());
        TintypeServer::actingAs($viewer)->tool(ListBuilds::class, ['project' => 'ds', 'status' => 'pending'])->assertOk()->assertSee('"number":2');
        TintypeServer::actingAs($viewer)->tool(GetBuild::class, ['project' => $this->ref()])
            ->assertOk()->assertSee(['T / button--primary', 'changed', 'pending']);
        TintypeServer::actingAs($viewer)->tool(GetPullRequest::class, ['project' => 'ds', 'number' => 7])
            ->assertOk()->assertSee(['Blue button', '"pendingInHead":1', '"kind":"changed"']);

        $stranger = User::factory()->create();
        TintypeServer::actingAs($stranger)->tool(GetBuild::class, ['project' => $this->ref()])->assertHasErrors(['No project']);
    }

    public function test_get_snapshot_returns_new_baseline_and_diff_images(): void
    {
        $snap = $this->snap($this->head, 'button--primary');
        TintypeServer::actingAs($this->member($this->project, Role::Viewer))
            ->tool(GetSnapshot::class, ['project' => 'ds', 'snapshot' => $snap->id])
            ->assertOk()->assertSee(['new:', 'baseline:', '"baselineFromBuild": 1']);

        $elsewhere = $this->makeProject(['slug' => 'other']);
        $elsewhere->save();
        TintypeServer::actingAs($this->member($elsewhere))->tool(GetSnapshot::class, ['project' => 'other', 'snapshot' => $snap->id])->assertHasErrors(['not found']);
    }

    public function test_review_tools_respect_roles_and_record_source(): void
    {
        $snap = $this->snap($this->head, 'button--primary');
        $args = ['project' => 'ds', 'build' => 2, 'decision' => 'accepted', 'all' => true];
        TintypeServer::actingAs($this->member($this->project, Role::Viewer))->tool(ReviewSnapshots::class, $args)->assertHasErrors(['viewer']);
        $this->assertSame(ReviewState::Pending, $snap->fresh()->review_state);

        $reviewer = $this->member($this->project, Role::Reviewer);
        TintypeServer::actingAs($reviewer)->tool(ReviewSnapshots::class, array_merge($args, ['all' => false]))->assertHasErrors(['snapshot ids']);
        TintypeServer::actingAs($reviewer)->tool(ReviewSnapshots::class, $args)->assertOk()->assertSee(['"updated":1', 'accepted']);
        $this->assertSame(ReviewState::Accepted, $snap->fresh()->review_state);
        $this->assertDatabaseHas('reviews', ['snapshot_id' => $snap->id, 'source' => 'mcp', 'user_id' => $reviewer->id]);

        TintypeServer::actingAs($reviewer)->tool(CommentOnBuild::class, ['project' => 'ds', 'build' => 2, 'snapshot' => $snap->id, 'body' => 'Looks right'])->assertOk();
        $this->assertDatabaseHas('comments', ['snapshot_id' => $snap->id, 'body' => 'Looks right', 'user_id' => $reviewer->id]);
    }

    public function test_review_pull_request_with_accept_pending(): void
    {
        $reviewer = $this->member($this->project, Role::Reviewer);
        TintypeServer::actingAs($reviewer)->tool(ReviewPullRequest::class, ['project' => 'ds', 'number' => 7, 'state' => 'approved', 'accept_pending' => true])
            ->assertOk()->assertSee('"acceptedSnapshots":1');
        $this->assertSame('approved', PullRequest::where('number', 7)->value('review_status'));
        $this->assertSame(BuildStatus::Accepted, $this->head->fresh()->status);
    }
}
