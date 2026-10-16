<?php

namespace App\Http\Controllers;

use App\Models\Build;
use App\Models\Organization;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $orgs = $user->is_admin ? Organization::query()->orderBy('name')->get() : $user->organizations()->orderBy('name')->get();
        $orgs->load(['projects' => fn ($q) => $q->orderBy('name')]);

        return Inertia::render('dashboard', [
            'organizations' => $orgs->map(fn (Organization $o) => [
                'id' => $o->id,
                'name' => $o->name,
                'slug' => $o->slug,
                'role' => $o->roleOf($user)?->value ?? 'admin',
                'projects' => $o->projects->map(function ($p) {
                    $latest = Build::where('project_id', $p->id)->orderByDesc('number')->first();

                    return [
                        'id' => $p->id,
                        'name' => $p->name,
                        'slug' => $p->slug,
                        'repository' => $p->github_repository,
                        'latestBuild' => $latest ? ['number' => $latest->number, 'status' => $latest->status->value, 'branch' => $latest->branch, 'createdAt' => $latest->created_at->toIso8601String()] : null,
                    ];
                }),
            ]),
        ]);
    }
}
