<?php

use App\Http\Middleware\AuthenticateApiToken;
use App\Mcp\Servers\TintypeServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;

// OAuth discovery + dynamic client registration: MCP clients open the browser, the user
// signs in and approves, and the client receives a token (Passport authorization code).
// Registration is open (RFC 7591), so it is rate limited.
Route::middleware('throttle:20,1')->group(fn () => Mcp::oauthRoutes());

// OAuth access token, or a personal access token (Settings → API tokens) for headless clients.
Mcp::web('/mcp', TintypeServer::class)->middleware([AuthenticateApiToken::class, 'auth:api', 'throttle:120,1']);
