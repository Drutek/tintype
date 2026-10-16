<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Enums\Role;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and create a newly registered user. The first user becomes the
     * instance admin and owner of a new organization; afterwards sign-up is
     * closed unless TINTYPE_OPEN_REGISTRATION is set.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        $first = ! User::query()->exists();
        if (! $first && ! config('tintype.open_registration')) {
            throw ValidationException::withMessages(['email' => 'Registration is closed. Ask an administrator to add you to an organization.']);
        }

        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        return DB::transaction(function () use ($input, $first) {
            $user = User::create([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => $input['password'],
            ]);
            $user->forceFill(['email_verified_at' => now(), 'is_admin' => $first])->save();

            if ($first) {
                $org = Organization::create([
                    'name' => $input['organization'] ?? 'My Organization',
                    'slug' => Str::slug($input['organization'] ?? 'my-organization') ?: 'org',
                ]);
                $org->users()->attach($user->id, ['role' => Role::Owner->value]);
            }

            return $user;
        });
    }
}
