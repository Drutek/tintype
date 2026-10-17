<?php

namespace App\Mcp\Tools;

use App\Enums\Role;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('List the organizations and projects you can access, with your role and each project\'s latest build.')]
#[IsReadOnly]
class ListProjects extends TintypeTool
{
    public function handle(Request $request): ResponseFactory
    {
        $user = $this->user($request);
        $orgs = $user->is_admin ? Organization::orderBy('name')->get() : $user->organizations()->orderBy('name')->get();
        $out = $orgs->map(fn (Organization $o) => [
            'organization' => $o->slug,
            'name' => $o->name,
            'role' => ($o->roleOf($user) ?? Role::Admin)->value,
            'projects' => $o->projects()->orderBy('name')->get()->map(function (Project $p) use ($o) {
                $latest = $p->builds()->orderByDesc('number')->first();

                return [
                    'project' => "{$o->slug}/{$p->slug}",
                    'name' => $p->name,
                    'defaultBranch' => $p->default_branch,
                    'repository' => $p->github_repository,
                    'latestBuild' => $latest ? ['number' => $latest->number, 'status' => $latest->status->value, 'branch' => $latest->branch] : null,
                ];
            })->values(),
        ])->values()->all();

        return Response::structured(['organizations' => $out]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
