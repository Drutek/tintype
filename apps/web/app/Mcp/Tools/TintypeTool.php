<?php

namespace App\Mcp\Tools;

use App\Enums\Role;
use App\Models\Build;
use App\Models\Project;
use App\Models\Snapshot;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Tool;

/**
 * Shared lookups for Tintype tools. Every tool acts as the token's user, with
 * that user's role in the project's organization (same rules as the web UI).
 */
abstract class TintypeTool extends Tool
{
    protected function user(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User) {
            throw new AuthorizationException('Not authenticated.');
        }

        return $user;
    }

    protected function projectSchema(JsonSchema $schema): Type
    {
        return $schema->string()->description('Project as "organization/project" slugs (from list-projects); the project slug alone works when unambiguous.')->required();
    }

    /** @return array{0: Project, 1: Role} */
    protected function project(Request $request): array
    {
        $user = $this->user($request);
        $ref = trim((string) $request->get('project'));
        [$org, $slug] = str_contains($ref, '/') ? explode('/', $ref, 2) : [null, $ref];
        $matches = Project::with('organization')->where('slug', $slug)
            ->when($org, fn ($q) => $q->whereHas('organization', fn ($o) => $o->where('slug', $org)))
            ->get()
            ->filter(fn (Project $p) => $user->is_admin || $p->organization->roleOf($user) !== null);
        if ($matches->count() !== 1) {
            throw ValidationException::withMessages(['project' => $matches->isEmpty()
                ? "No project \"{$ref}\" that you can access. Use list-projects."
                : "\"{$ref}\" is ambiguous; use organization/project."]);
        }
        $project = $matches->first();

        return [$project, $project->organization->roleOf($user) ?? Role::Admin];
    }

    protected function requireReviewer(Role $role): void
    {
        if (! $role->canReview()) {
            throw new AuthorizationException('Your role in this organization is viewer; reviewing needs reviewer or above.');
        }
    }

    /** The numbered build, or the project's latest build (optionally on a branch). */
    protected function build(Project $project, mixed $number, ?string $branch = null): Build
    {
        $build = $project->builds()
            ->when($number, fn ($q) => $q->where('number', (int) $number), fn ($q) => $q->when($branch, fn ($b) => $b->where('branch', $branch))->orderByDesc('number'))
            ->first();
        if (! $build) {
            throw ValidationException::withMessages(['build' => $number ? "Build {$number} not found." : 'No builds yet.']);
        }

        return $build->setRelation('project', $project);
    }

    /** @return array<string, mixed> */
    protected function buildSummary(Build $build): array
    {
        return [
            'number' => $build->number,
            'status' => $build->status->value,
            'branch' => $build->branch,
            'commit' => $build->commit_sha,
            'pullRequest' => $build->pr_number,
            'createdAt' => $build->created_at->toIso8601String(),
            'counts' => [
                'snapshots' => $build->snapshot_count, 'changed' => $build->changed_count, 'new' => $build->new_count,
                'errors' => $build->error_count, 'unchanged' => $build->unchanged_count, 'removed' => $build->removed_count,
                'inconsistent' => $build->inconsistent_count,
            ],
            'supersededBy' => $build->options['supersededBy'] ?? null,
            'url' => $build->webUrl(),
        ];
    }

    /** @return array<string, mixed> */
    protected function snapshotSummary(Snapshot $s): array
    {
        return [
            'id' => $s->id,
            'story' => "{$s->story->title} / {$s->story->name}",
            'storyId' => $s->story->story_id,
            'browser' => $s->browser,
            'mode' => $s->mode_name,
            'status' => $s->status->value,
            'review' => $s->review_state?->value,
            'diffPercent' => $s->diff_ratio !== null ? round($s->diff_ratio * 100, 2) : null,
            'error' => $s->error,
        ];
    }
}
