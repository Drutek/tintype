<?php

namespace Tests\Feature;

use App\Enums\BuildStatus;
use App\Enums\ReviewState;
use App\Enums\Role;
use App\Enums\SnapshotStatus;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Tintype;
use Tests\TestCase;

class WebPagesTest extends TestCase
{
    use RefreshDatabase, Tintype;

    public function test_first_registration_creates_admin_and_org_then_closes(): void
    {
        $this->post('/register', ['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'Password-123!', 'password_confirmation' => 'Password-123!'])
            ->assertRedirect();
        $ada = User::where('email', 'ada@example.com')->firstOrFail();
        $this->assertTrue($ada->is_admin);
        $this->assertSame(Role::Owner, Organization::firstOrFail()->roleOf($ada));

        auth()->logout();
        $this->post('/register', ['name' => 'Bob', 'email' => 'bob@example.com', 'password' => 'Password-123!', 'password_confirmation' => 'Password-123!'])
            ->assertSessionHasErrors('email');
        $this->assertDatabaseMissing('users', ['email' => 'bob@example.com']);
    }

    public function test_pages_render_for_members(): void
    {
        $project = $this->makeProject();
        $owner = $this->member($project, Role::Owner);
        $build = $this->completedBuild($project, 'abc1234', 'main', [
            'button--a' => ['sha' => str_repeat('1', 64), 'status' => SnapshotStatus::Changed, 'review' => ReviewState::Pending],
            'button--b' => ['sha' => str_repeat('2', 64), 'status' => SnapshotStatus::Unchanged],
        ], BuildStatus::Pending);
        $org = $project->organization;

        $this->actingAs($owner)->get('/dashboard')->assertInertia(fn (Assert $p) => $p->component('dashboard')->has('organizations', 1)->where('organizations.0.projects.0.latestBuild.status', 'pending'));
        $this->get(route('organizations.show', $org))->assertInertia(fn (Assert $p) => $p->component('organizations/show')->has('projects', 1));
        $this->get(route('projects.show', [$org, $project]))->assertInertia(fn (Assert $p) => $p->component('projects/show')->has('builds.data', 1));
        $this->get(route('builds.show', [$org, $project, $build->number]))->assertInertia(fn (Assert $p) => $p->component('builds/show')
            ->has('snapshots', 2)->where('snapshots.0.status', 'changed')->where('snapshots.0.needsReview', true));
        $this->get(route('projects.library', [$org, $project]))->assertInertia(fn (Assert $p) => $p->component('projects/library')->has('components', 1));
        $this->get(route('projects.settings', [$org, $project]))->assertInertia(fn (Assert $p) => $p->component('projects/settings')->where('project.settings.diff_threshold', 0.063));
        $this->get(route('pulls.index', [$org, $project]))->assertInertia(fn (Assert $p) => $p->component('pulls/index'));
    }

    public function test_non_members_and_viewers_are_restricted(): void
    {
        $project = $this->makeProject();
        $build = $this->completedBuild($project, 'abc1234', 'main', ['a--b' => ['sha' => str_repeat('1', 64), 'status' => SnapshotStatus::New, 'review' => ReviewState::Pending]], BuildStatus::Pending);
        $org = $project->organization;
        $stranger = User::factory()->create();
        $this->actingAs($stranger)->get(route('projects.show', [$org, $project]))->assertNotFound();

        $viewer = $this->member($project, Role::Viewer);
        $this->actingAs($viewer)->get(route('builds.show', [$org, $project, $build->number]))->assertOk();
        $this->actingAs($viewer)->post(route('builds.review', [$org, $project, $build->number]), ['decision' => 'accepted', 'scope' => 'all'])->assertForbidden();
        $this->actingAs($viewer)->put(route('projects.update', [$org, $project]), ['name' => 'x', 'default_branch' => 'main'])->assertForbidden();
        $this->get('/admin')->assertForbidden();
    }

    public function test_settings_update_and_token_rotation(): void
    {
        $project = $this->makeProject();
        $owner = $this->member($project);
        $org = $project->organization;
        $this->actingAs($owner)->put(route('projects.update', [$org, $project]), [
            'name' => 'Renamed', 'default_branch' => 'develop', 'github_repository' => 'acme/web',
            'settings' => ['browsers' => ['chromium', 'firefox'], 'diff_threshold' => 0.1, 'allowed_hosts' => ['*.example.com']],
        ])->assertRedirect();
        $project->refresh();
        $this->assertSame(['Renamed', 'develop', ['chromium', 'firefox'], 0.1], [$project->name, $project->default_branch, $project->setting('browsers'), $project->setting('diff_threshold')]);

        $old = $this->projectToken;
        $this->post(route('projects.token', [$org, $project]))->assertSessionHas('newToken');
        $this->getJson('/api/v1/builds/1', ['Authorization' => "Bearer {$old}"])->assertUnauthorized();
    }

    public function test_comments_on_snapshots(): void
    {
        $project = $this->makeProject();
        $owner = $this->member($project);
        $build = $this->completedBuild($project, 'abc1234', 'main', ['a--b' => ['sha' => str_repeat('1', 64), 'status' => SnapshotStatus::Changed, 'review' => ReviewState::Pending]], BuildStatus::Pending);
        $snap = $build->snapshots()->first();
        $org = $project->organization;
        $this->actingAs($owner)->post(route('builds.comments.store', [$org, $project, $build->number]), ['body' => 'Is this intended?', 'snapshot_id' => $snap->id])->assertRedirect();
        $this->get(route('builds.show', [$org, $project, $build->number]))->assertInertia(fn (Assert $p) => $p->where('comments.0.body', 'Is this intended?')->where('snapshots.0.commentCount', 1));
    }
}
