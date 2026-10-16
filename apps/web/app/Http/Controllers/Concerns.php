<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Http\Request;

trait Concerns
{
    protected function role(Request $request, Organization $organization): Role
    {
        $role = $organization->roleOf($request->user());
        abort_unless($role !== null || $request->user()?->is_admin, 404);

        return $role ?? Role::Admin;
    }

    protected function requireReviewer(Request $request, Organization $organization): Role
    {
        $role = $this->role($request, $organization);
        abort_unless($role->canReview(), 403);

        return $role;
    }

    protected function requireManager(Request $request, Organization $organization): Role
    {
        $role = $this->role($request, $organization);
        abort_unless($role->canManage(), 403);

        return $role;
    }

    protected function scoped(Organization $organization, Project $project): Project
    {
        abort_unless($project->organization_id === $organization->id, 404);
        $project->setRelation('organization', $organization);

        return $project;
    }
}
