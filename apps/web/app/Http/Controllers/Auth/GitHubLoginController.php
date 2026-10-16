<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\GitHub\GitHubApp;
use App\Services\Sso;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** "Sign in with GitHub" using the install's GitHub App OAuth credentials. */
class GitHubLoginController extends Controller
{
    public function redirect(Request $request, GitHubApp $app): RedirectResponse
    {
        $creds = $app->credentials();
        abort_unless($creds && ! empty($creds['client_id']), 404);
        $state = Str::random(40);
        $request->session()->put('github_login_state', $state);
        $query = http_build_query([
            'client_id' => $creds['client_id'],
            'redirect_uri' => url('/auth/github/callback'),
            'state' => $state,
            'scope' => 'read:user user:email',
        ]);

        return redirect()->away(rtrim((string) config('tintype.github.web_url'), '/').'/login/oauth/authorize?'.$query);
    }

    public function callback(Request $request, GitHubApp $app, Sso $sso): RedirectResponse
    {
        $creds = $app->credentials();
        abort_unless($creds && ! empty($creds['client_id']), 404);
        $expected = (string) $request->session()->pull('github_login_state');
        abort_unless($expected !== '' && hash_equals($expected, (string) $request->query('state')), 403);

        $token = Http::acceptJson()->post(rtrim((string) config('tintype.github.web_url'), '/').'/login/oauth/access_token', [
            'client_id' => $creds['client_id'],
            'client_secret' => $creds['client_secret'],
            'code' => $request->query('code'),
            'redirect_uri' => url('/auth/github/callback'),
        ])->json('access_token');
        abort_unless(is_string($token) && $token !== '', 403, 'GitHub sign-in failed');

        $api = Http::withToken($token)->withHeaders(['Accept' => 'application/vnd.github+json', 'User-Agent' => 'tintype']);
        $profile = $api->get($app->apiUrl('user'))->throw()->json();
        $emails = $api->get($app->apiUrl('user/emails'))->json();
        $primary = null;
        foreach (is_array($emails) ? $emails : [] as $e) {
            if (is_array($e) && ($e['primary'] ?? false) && ($e['verified'] ?? false)) {
                $primary = $e;
                break;
            }
        }

        try {
            $user = $sso->resolve('github_id', (int) $profile['id'], $primary['email'] ?? null, $primary !== null, (string) ($profile['name'] ?? $profile['login']));
        } catch (ValidationException $e) {
            return redirect()->route('login')->withErrors($e->errors());
        }
        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }
}
