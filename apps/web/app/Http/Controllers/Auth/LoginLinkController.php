<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class LoginLinkController extends Controller
{
    public function __invoke(Request $request, User $user): RedirectResponse
    {
        $expected = substr(hash('sha256', $user->password.'|'.$user->remember_token), 0, 16);
        abort_unless(hash_equals($expected, (string) $request->query('v')), 403, 'This sign-in link has already been used.');
        // Rotating the remember token invalidates the link after one use.
        $user->forceFill(['remember_token' => Str::random(60)])->save();
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }
}
