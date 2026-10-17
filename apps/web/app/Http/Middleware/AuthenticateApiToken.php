<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Personal access tokens (`Bearer tu_…`) for clients that can't do the OAuth flow.
 * Any other bearer token is left to the `auth:api` (OAuth) middleware that follows.
 */
class AuthenticateApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();
        if (! $bearer || ! str_starts_with($bearer, 'tu_')) {
            return $next($request);
        }
        $token = ApiToken::findByToken($bearer);
        if (! $token) {
            return response()->json(['message' => 'Invalid personal access token'], 401);
        }
        if (! $token->last_used_at || $token->last_used_at->lt(now()->subMinute())) {
            $token->forceFill(['last_used_at' => now()])->saveQuietly();
        }
        Auth::guard('api')->setUser($token->user);
        Auth::shouldUse('api');

        return $next($request);
    }
}
