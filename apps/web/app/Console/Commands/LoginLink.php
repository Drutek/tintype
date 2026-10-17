<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\URL;

class LoginLink extends Command
{
    protected $signature = 'tintype:login-link {email} {--minutes=15}';

    protected $description = 'Print a one-time sign-in link for a user (account recovery, first-time SSO setup)';

    public function handle(): int
    {
        $email = $this->argument('email');
        $user = is_string($email) ? User::where('email', $email)->first() : null;
        if (! $user) {
            $this->error('No such user');

            return self::FAILURE;
        }
        $minutes = (int) $this->option('minutes');
        // Bind the link to the current password hash + remember token so it stops working after use or a password change.
        $this->line(URL::temporarySignedRoute('login.link', now()->addMinutes(max(1, $minutes)), [
            'user' => $user->id,
            'v' => substr(hash('sha256', $user->password.'|'.$user->remember_token), 0, 16),
        ]));

        return self::SUCCESS;
    }
}
