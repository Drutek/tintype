<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Sso;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Generic OpenID Connect sign-in (Okta, Entra ID, Google Workspace, Keycloak, …)
 * using the authorization-code flow with PKCE. Identity comes from the
 * userinfo endpoint, fetched server-to-server with the access token.
 */
class OidcLoginController extends Controller
{
    /** @return array<string, mixed> */
    private function discovery(): array
    {
        $issuer = rtrim((string) config('tintype.oidc.issuer'), '/');
        abort_if($issuer === '', 404);

        return Cache::remember('oidc-discovery-'.md5($issuer), 3600, fn () => Http::timeout(10)->get($issuer.'/.well-known/openid-configuration')->throw()->json());
    }

    public function redirect(Request $request): RedirectResponse
    {
        $d = $this->discovery();
        $state = Str::random(40);
        $verifier = Str::random(64);
        $request->session()->put('oidc', ['state' => $state, 'verifier' => $verifier]);
        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => config('tintype.oidc.client_id'),
            'redirect_uri' => url('/auth/oidc/callback'),
            'scope' => 'openid email profile',
            'state' => $state,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ]);

        return redirect()->away($d['authorization_endpoint'].'?'.$query);
    }

    public function callback(Request $request, Sso $sso): RedirectResponse
    {
        $d = $this->discovery();
        $session = $request->session()->pull('oidc');
        abort_unless(is_array($session) && hash_equals((string) $session['state'], (string) $request->query('state')), 403);

        $tokens = Http::asForm()->timeout(15)->post($d['token_endpoint'], [
            'grant_type' => 'authorization_code',
            'code' => $request->query('code'),
            'redirect_uri' => url('/auth/oidc/callback'),
            'client_id' => config('tintype.oidc.client_id'),
            'client_secret' => config('tintype.oidc.client_secret'),
            'code_verifier' => $session['verifier'],
        ])->throw()->json();
        $info = Http::withToken((string) ($tokens['access_token'] ?? ''))->timeout(15)->get($d['userinfo_endpoint'])->throw()->json();
        abort_unless(! empty($info['sub']), 403, 'Identity provider returned no subject');

        try {
            $user = $sso->resolve(
                'oidc_sub',
                (string) $info['sub'],
                $info['email'] ?? null,
                (bool) ($info['email_verified'] ?? config('tintype.oidc.trust_email')),
                (string) ($info['name'] ?? $info['preferred_username'] ?? ''),
                config('tintype.oidc.auto_join_org') ?: null,
            );
        } catch (ValidationException $e) {
            return redirect()->route('login')->withErrors($e->errors());
        }
        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }
}
