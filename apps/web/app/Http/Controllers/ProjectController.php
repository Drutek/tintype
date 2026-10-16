<?php

namespace App\Http\Controllers;

use App\Enums\BuildStatus;
use App\Models\Build;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Snapshot;
use App\Models\Story;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    use Concerns;

    public function store(Request $request, Organization $organization): RedirectResponse
    {
        $this->requireManager($request, $organization);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'github_repository' => ['nullable', 'string', 'regex:/^[\w.-]+\/[\w.-]+$/'],
            'default_branch' => ['nullable', 'string', 'max:255'],
        ]);
        $slug = Str::slug($data['name']) ?: 'project';
        $base = $slug;
        for ($i = 2; $organization->projects()->where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }
        $project = new Project([
            'organization_id' => $organization->id,
            'name' => $data['name'],
            'slug' => $slug,
            'github_repository' => $data['github_repository'] ?? null,
            'default_branch' => $data['default_branch'] ?: 'main',
            'token_prefix' => '',
            'token_hash' => '',
        ]);
        $token = $project->rotateToken();

        return redirect()->route('projects.settings', [$organization, $project])->with('newToken', $token);
    }

    public function show(Request $request, Organization $organization, Project $project): Response
    {
        $this->scoped($organization, $project);
        $role = $this->role($request, $organization);
        $query = $project->builds()->orderByDesc('number');
        if ($branch = $request->string('branch')->toString()) {
            $query->where('branch', $branch);
        }
        if ($status = $request->string('status')->toString()) {
            $query->where('status', $status);
        }
        $builds = $query->paginate(30)->withQueryString()->through(fn (Build $b) => BuildController::row($b));

        return Inertia::render('projects/show', [
            'organization' => $organization->only('id', 'name', 'slug'),
            'project' => self::projectProps($project),
            'role' => $role->value,
            'builds' => $builds,
            'branches' => $project->builds()->select('branch')->distinct()->orderBy('branch')->limit(200)->pluck('branch'),
            'filters' => ['branch' => $request->string('branch')->toString() ?: null, 'status' => $request->string('status')->toString() ?: null],
        ]);
    }

    /** Component library: latest approved snapshot of every story on a branch. */
    public function library(Request $request, Organization $organization, Project $project): Response
    {
        $this->scoped($organization, $project);
        $this->role($request, $organization);
        $branch = $request->string('branch', $project->default_branch)->toString();
        $build = $project->builds()->where('branch', $branch)
            ->whereIn('status', array_map(fn ($s) => $s->value, array_filter(BuildStatus::cases(), fn ($s) => $s->isComplete())))
            ->orderByDesc('number')->first();
        $stories = [];
        if ($build) {
            $snaps = Snapshot::where('build_id', $build->id)->with(['story', 'baseline'])->get();
            foreach ($snaps as $s) {
                $approved = $s->isApproved() ? $s : $s->baseline;
                if (! $approved?->image_sha) {
                    continue;
                }
                $stories[$s->story->title][] = [
                    'storyId' => $s->story->story_id,
                    'name' => $s->story->name,
                    'mode' => $s->mode_name,
                    'browser' => $s->browser,
                    'image' => route('images.show', $approved->image_sha),
                    'width' => $approved->width,
                    'height' => $approved->height,
                ];
            }
            ksort($stories);
        }

        return Inertia::render('projects/library', [
            'organization' => $organization->only('id', 'name', 'slug'),
            'project' => self::projectProps($project),
            'branch' => $branch,
            'build' => $build ? ['number' => $build->number, 'status' => $build->status->value, 'commit' => $build->commit_sha] : null,
            'components' => collect($stories)->map(fn ($items, $title) => ['title' => $title, 'stories' => $items])->values(),
            'branches' => $project->builds()->select('branch')->distinct()->orderBy('branch')->limit(200)->pluck('branch'),
        ]);
    }

    public function settings(Request $request, Organization $organization, Project $project): Response
    {
        $this->scoped($organization, $project);
        $role = $this->role($request, $organization);

        return Inertia::render('projects/settings', [
            'organization' => $organization->only('id', 'name', 'slug'),
            'project' => self::projectProps($project) + ['settings' => $project->publicSettings()],
            'role' => $role->value,
            'newToken' => session('newToken'),
            'tokenPrefix' => $project->token_prefix,
            'storyCount' => Story::where('project_id', $project->id)->count(),
            'apiUrl' => url('/'),
        ]);
    }

    public function update(Request $request, Organization $organization, Project $project): RedirectResponse
    {
        $this->scoped($organization, $project);
        $this->requireManager($request, $organization);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'default_branch' => ['required', 'string', 'max:255'],
            'github_repository' => ['nullable', 'string', 'regex:/^[\w.-]+\/[\w.-]+$/'],
            'settings' => ['array'],
            'settings.browsers' => ['array', 'min:1'],
            'settings.browsers.*' => [Rule::in(['chromium', 'firefox', 'webkit'])],
            'settings.diff_threshold' => ['numeric', 'between:0,1'],
            'settings.include_anti_aliasing' => ['boolean'],
            'settings.allowed_hosts' => ['array'],
            'settings.allowed_hosts.*' => ['string', 'max:255'],
            'settings.fixed_time' => ['string', 'date'],
            'settings.device_scale_factor' => ['numeric', 'between:1,3'],
            'settings.viewport_width' => ['integer', 'between:200,4000'],
            'settings.viewport_height' => ['integer', 'between:200,4000'],
            'settings.auto_accept_default_branch' => ['boolean'],
            'settings.retention_days' => ['integer', 'between:1,3650'],
            'settings.storybook_public' => ['boolean'],
            'settings.ui_review' => ['boolean'],
            'settings.a11y' => ['boolean'],
            'settings.notify_webhook_url' => ['nullable', 'url:https,http', 'max:500'],
            'settings.notify_slack_webhook_url' => ['nullable', 'url:https', 'max:500'],
            'settings.scm' => [Rule::in(['github', 'gitlab', 'bitbucket'])],
            'settings.scm_repository' => ['nullable', 'string', 'max:255'],
            'settings.scm_url' => ['nullable', 'url:https,http', 'max:255'],
            'scm_token' => ['nullable', 'string', 'max:500'],
        ]);
        // Only user-editable keys; internal flags (rebaseline_pending, encrypted secrets) are set elsewhere.
        $incoming = Arr::only($data['settings'] ?? [], array_diff(array_keys(Project::DEFAULT_SETTINGS), ['rebaseline_pending']));
        $project->fill([
            'name' => $data['name'],
            'default_branch' => $data['default_branch'],
            'github_repository' => $data['github_repository'] ?? null,
            'settings' => array_merge($project->settings ?? [], $incoming),
        ]);
        if (! empty($data['scm_token'])) {
            $project->setScmToken($data['scm_token']);
        }
        if ($project->isDirty('github_repository')) {
            $project->github_installation_id = null;
        }
        $project->save();

        return back();
    }

    public function rotateToken(Request $request, Organization $organization, Project $project): RedirectResponse
    {
        $this->scoped($organization, $project);
        $this->requireManager($request, $organization);

        return back()->with('newToken', $project->rotateToken());
    }

    /** Accept the next default-branch build wholesale (after a capture-environment change). */
    public function rebaseline(Request $request, Organization $organization, Project $project): RedirectResponse
    {
        $this->scoped($organization, $project);
        $this->requireManager($request, $organization);
        $project->forceFill(['settings' => array_merge($project->settings ?? [], ['rebaseline_pending' => true])])->save();

        return back();
    }

    public function destroy(Request $request, Organization $organization, Project $project): RedirectResponse
    {
        $this->scoped($organization, $project);
        $this->requireManager($request, $organization);
        $project->delete();

        return redirect()->route('organizations.show', $organization);
    }

    /** @return array<string, mixed> */
    public static function projectProps(Project $project): array
    {
        return [
            'id' => $project->id,
            'name' => $project->name,
            'slug' => $project->slug,
            'defaultBranch' => $project->default_branch,
            'repository' => $project->github_repository,
        ];
    }
}
