<?php

use App\Http\Middleware\AuthenticateApiToken;
use App\Mcp\Servers\TintypeServer;
use Laravel\Mcp\Facades\Mcp;

// Personal access token (Settings → API tokens) as `Authorization: Bearer …`.
Mcp::web('/mcp', TintypeServer::class)->middleware([AuthenticateApiToken::class, 'throttle:120,1']);
