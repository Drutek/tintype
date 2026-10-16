<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class OrganizationController extends Controller
{
    use Concerns;

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->is_admin || config('tintype.open_registration'), 403);
        $data = $request->validate(['name' => ['required', 'string', 'max:100']]);
        $slug = Str::slug($data['name']) ?: 'org';
        $base = $slug;
        for ($i = 2; Organization::where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }
        $org = Organization::create(['name' => $data['name'], 'slug' => $slug]);
        $org->users()->attach($request->user()->id, ['role' => Role::Owner->value]);

        return redirect()->route('organizations.show', $org);
    }

    public function show(Request $request, Organization $organization): Response
    {
        $role = $this->role($request, $organization);

        return Inertia::render('organizations/show', [
            'organization' => ['id' => $organization->id, 'name' => $organization->name, 'slug' => $organization->slug],
            'role' => $role->value,
            'projects' => $organization->projects()->orderBy('name')->get(['id', 'name', 'slug', 'github_repository', 'default_branch']),
            'members' => $organization->users()->orderBy('name')->cursor()->map(fn ($u) => [
                'id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'role' => $u->pivot->getAttribute('role'),
            ])->collect(),
            'newToken' => session('newToken'),
            'newPassword' => session('newPassword'),
        ]);
    }

    /** Add an existing user by email, or create an account with a one-time password. */
    public function addMember(Request $request, Organization $organization): RedirectResponse
    {
        $this->requireManager($request, $organization);
        $data = $request->validate([
            'email' => ['required', 'email'],
            'name' => ['nullable', 'string', 'max:100'],
            'role' => ['required', Rule::enum(Role::class)],
        ]);
        $user = User::where('email', $data['email'])->first();
        $password = null;
        if (! $user) {
            $password = Str::password(16, symbols: false);
            $user = User::create(['name' => $data['name'] ?: Str::before($data['email'], '@'), 'email' => $data['email'], 'password' => $password]);
            $user->forceFill(['email_verified_at' => now()])->save();
        }
        $organization->users()->syncWithoutDetaching([$user->id => ['role' => $data['role']]]);
        $organization->users()->updateExistingPivot($user->id, ['role' => $data['role']]);

        return back()->with('newPassword', $password ? ['email' => $user->email, 'password' => $password] : null);
    }

    public function removeMember(Request $request, Organization $organization, User $user): RedirectResponse
    {
        $this->requireManager($request, $organization);
        $owners = $organization->users()->wherePivot('role', Role::Owner->value)->count();
        abort_if($owners <= 1 && $organization->roleOf($user) === Role::Owner, 422, 'An organization needs at least one owner');
        $organization->users()->detach($user->id);

        return back();
    }
}
