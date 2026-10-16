<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Finds or provisions the local user for an external identity. */
class Sso
{
    /**
     * @param  'github_id'|'oidc_sub'  $column
     */
    public function resolve(string $column, string|int $externalId, ?string $email, bool $emailVerified, string $name, ?string $joinOrg = null): User
    {
        $user = User::where($column, $externalId)->first();
        if (! $user && $email && $emailVerified) {
            $user = User::where('email', $email)->first();
        }
        if ($user) {
            $user->forceFill([$column => $externalId])->save();

            return $user;
        }

        $first = ! User::query()->exists();
        if (! $first && ! config('tintype.open_registration') && ! $joinOrg) {
            throw ValidationException::withMessages(['email' => 'No account matches this identity. Ask an administrator to add you first.']);
        }
        if (! $email || ! $emailVerified) {
            throw ValidationException::withMessages(['email' => 'Your identity provider did not return a verified email address.']);
        }

        return DB::transaction(function () use ($column, $externalId, $email, $name, $first, $joinOrg) {
            $user = new User(['name' => $name ?: Str::before($email, '@'), 'email' => $email, 'password' => Str::random(40)]);
            $user->forceFill([$column => $externalId, 'email_verified_at' => now(), 'is_admin' => $first])->save();
            if ($first) {
                $org = Organization::create(['name' => 'My Organization', 'slug' => 'my-organization']);
                $org->users()->attach($user->id, ['role' => Role::Owner->value]);
            } elseif ($joinOrg) {
                $org = Organization::where('slug', $joinOrg)->first();
                $org?->users()->syncWithoutDetaching([$user->id => ['role' => Role::Reviewer->value]]);
            }

            return $user;
        });
    }
}
