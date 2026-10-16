<?php

use App\Models\Build;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('builds.{build}', function (User $user, int $build) {
    $model = Build::with('project.organization')->find($build);

    return $model && $model->project->organization->roleOf($user) !== null;
});

Broadcast::channel('projects.{project}', function (User $user, int $project) {
    $model = Project::with('organization')->find($project);

    return $model && $model->organization->roleOf($user) !== null;
});
