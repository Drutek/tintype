<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CreateAdmin extends Command
{
    protected $signature = 'tintype:admin {email} {--name=Admin} {--password=} {--org=}';

    protected $description = 'Create (or promote) an instance admin, optionally owning an organization';

    public function handle(): int
    {
        $email = $this->argument('email');
        if (! is_string($email)) {
            $this->error('The email argument must be a string.');

            return self::FAILURE;
        }
        $password = $this->option('password');
        if (! is_string($password) || ! $password) {
            $password = Str::password(16, symbols: false);
        }
        $user = User::firstOrNew(['email' => $email]);
        $created = ! $user->exists;
        if ($created) {
            $user->fill(['name' => $this->option('name'), 'password' => $password]);
        }
        $user->forceFill(['is_admin' => true, 'email_verified_at' => $user->email_verified_at ?? now()])->save();
        $org = $this->option('org');
        if (is_string($org) && $org) {
            $organization = Organization::firstOrCreate(['slug' => Str::slug($org)], ['name' => $org]);
            $organization->users()->syncWithoutDetaching([$user->id => ['role' => Role::Owner->value]]);
        }
        $this->info($created ? "Created admin {$user->email} with password: {$password}" : "Promoted {$user->email} to admin");

        return self::SUCCESS;
    }
}
