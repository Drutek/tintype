<?php

namespace App\Http\Middleware;

use App\Models\Project;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** CLI requests: `Authorization: Bearer <project token>`. */
class AuthenticateProjectToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $project = Project::findByToken($request->bearerToken());
        if (! $project) {
            return response()->json(['message' => 'Invalid project token'], 401);
        }
        $request->attributes->set('project', $project);

        return $next($request);
    }
}
