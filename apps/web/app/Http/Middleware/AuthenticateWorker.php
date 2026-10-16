<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Capture worker requests: `Authorization: Bearer <TINTYPE_WORKER_TOKEN>`. */
class AuthenticateWorker
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('tintype.worker_token');
        $given = (string) $request->bearerToken();
        if ($expected === '' || ! hash_equals($expected, $given)) {
            return response()->json(['message' => 'Invalid worker token'], 401);
        }
        $arch = (string) $request->header('X-Tintype-Arch', '');
        if ($arch !== '' && ! config('tintype.allow_any_arch') && ! in_array($arch, ['x64', 'x86_64', 'amd64'], true)) {
            return response()->json(['message' => "Workers on {$arch} are not allowed; baselines must come from x86_64 (set TINTYPE_ALLOW_ANY_ARCH=true for development)"], 403);
        }

        return $next($request);
    }
}
