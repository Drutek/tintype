<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Tintype;
use Tests\TestCase;

class SsoAndAuditTest extends TestCase
{
    use RefreshDatabase, Tintype;

    private function githubApp(): void
    {
        AppSetting::put('github_app', ['id' => 1, 'slug' => 's', 'pem' => 'x', 'client_id' => 'cid', 'client_secret' => 'csec']);
    }

    public function test_login_page_advertises_configured_sso(): void
    {
        $this->get('/login')->assertInertia(fn (Assert $p) => $p->where('sso.github', false)->where('sso.oidc', null)->where('canRegister', false));
        $this->githubApp();
        config(['tintype.oidc.issuer' => 'https://idp.example.com', 'tintype.oidc.label' => 'Okta']);
        $this->get('/login')->assertInertia(fn (Assert $p) => $p->where('sso.github', true)->where('sso.oidc', 'Okta'));
    }

    public function test_github_sign_in_links_existing_account_by_verified_email(): void
    {
        $this->githubApp();
        $existing = User::factory()->create(['email' => 'ada@example.com']);
        Http::fake([
            'github.com/login/oauth/access_token' => Http::response(['access_token' => 'gho_x']),
            'api.github.com/user' => Http::response(['id' => 4242, 'login' => 'ada', 'name' => 'Ada']),
            'api.github.com/user/emails' => Http::response([['email' => 'ada@example.com', 'primary' => true, 'verified' => true]]),
        ]);
        $redirect = $this->get('/auth/github')->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith('https://github.com/login/oauth/authorize?', $redirect);
        parse_str((string) parse_url($redirect, PHP_URL_QUERY), $q);

        $this->get('/auth/github/callback?code=abc&state=wrong')->assertForbidden();
        $this->get('/auth/github')->assertRedirect();
        $state = session('github_login_state');
        $this->get("/auth/github/callback?code=abc&state={$state}")->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($existing);
        $this->assertSame(4242, $existing->fresh()->github_id);
        $this->assertSame('cid', $q['client_id']);
    }

    public function test_github_sign_in_rejects_unknown_users_when_registration_closed(): void
    {
        $this->githubApp();
        User::factory()->create();
        Http::fake([
            'github.com/login/oauth/access_token' => Http::response(['access_token' => 'gho_x']),
            'api.github.com/user' => Http::response(['id' => 7, 'login' => 'eve']),
            'api.github.com/user/emails' => Http::response([['email' => 'eve@example.com', 'primary' => true, 'verified' => true]]),
        ]);
        $this->get('/auth/github');
        $this->get('/auth/github/callback?code=abc&state='.session('github_login_state'))->assertRedirect(route('login'))->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_oidc_pkce_flow_provisions_into_auto_join_org(): void
    {
        $org = Organization::create(['name' => 'Acme', 'slug' => 'acme']);
        User::factory()->create(); // not the first user
        config([
            'tintype.oidc.issuer' => 'https://idp.example.com',
            'tintype.oidc.client_id' => 'tintype',
            'tintype.oidc.client_secret' => 'shh',
            'tintype.oidc.auto_join_org' => 'acme',
        ]);
        Http::fake([
            'idp.example.com/.well-known/openid-configuration' => Http::response([
                'authorization_endpoint' => 'https://idp.example.com/authorize',
                'token_endpoint' => 'https://idp.example.com/token',
                'userinfo_endpoint' => 'https://idp.example.com/userinfo',
            ]),
            'idp.example.com/token' => Http::response(['access_token' => 'at', 'id_token' => 'x']),
            'idp.example.com/userinfo' => Http::response(['sub' => 'u-1', 'email' => 'grace@example.com', 'email_verified' => true, 'name' => 'Grace']),
        ]);
        $location = $this->get('/auth/oidc')->assertRedirect()->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $q);
        $this->assertSame('S256', $q['code_challenge_method']);
        $verifier = session('oidc')['verifier'];
        $this->get("/auth/oidc/callback?code=c&state={$q['state']}")->assertRedirect(route('dashboard'));

        $grace = User::where('email', 'grace@example.com')->firstOrFail();
        $this->assertAuthenticatedAs($grace);
        $this->assertSame(Role::Reviewer, $org->roleOf($grace));
        Http::assertSent(fn ($r) => $r->url() === 'https://idp.example.com/token' && $r['code_verifier'] === $verifier && $r['client_secret'] === 'shh');
    }

    public function test_audit_log_records_admin_actions(): void
    {
        $project = $this->makeProject();
        $owner = $this->member($project, Role::Owner);
        $org = $project->organization;
        $this->actingAs($owner)->post(route('projects.token', [$org, $project]));
        $this->post(route('organizations.members.store', $org), ['email' => 'new@example.com', 'role' => 'viewer']);
        $this->assertEqualsCanonicalizing(['project.token_rotated', 'member.added'], AuditLog::pluck('action')->all());
        $this->get(route('organizations.show', $org))->assertInertia(fn (Assert $p) => $p->has('audit', 2)->where('audit.0.user', $owner->name));

        $viewer = User::where('email', 'new@example.com')->firstOrFail();
        $this->actingAs($viewer)->get(route('organizations.show', $org))->assertInertia(fn (Assert $p) => $p->has('audit', 0));
    }

    public function test_one_time_login_link(): void
    {
        $user = User::factory()->create();
        Artisan::call('tintype:login-link', ['email' => $user->email]);
        $url = trim(Artisan::output());
        $this->get($url)->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
        auth()->logout();
        $this->get($url)->assertForbidden(); // single use
        $this->get(preg_replace('/signature=[^&]+/', 'signature=x', $url))->assertForbidden();
    }
}
