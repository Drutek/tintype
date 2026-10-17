<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\AuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Personal access tokens, used by MCP clients. */
class ApiTokenController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('settings/tokens', [
            'tokens' => $request->user()->apiTokens()->latest('id')->get()->map(fn (ApiToken $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'prefix' => $t->token_prefix,
                'lastUsedAt' => $t->last_used_at?->toIso8601String(),
                'createdAt' => $t->created_at->toIso8601String(),
            ]),
            // Plaintext of a token created on the previous request; shown once.
            'newToken' => $request->session()->get('newToken'),
            'mcpUrl' => url('/mcp'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100']]);
        [$token, $plain] = ApiToken::issue($request->user(), $data['name']);
        AuditLog::record('api_token.created', null, $token, ['name' => $token->name]);

        return back()->with('newToken', $plain);
    }

    public function destroy(Request $request, ApiToken $token): RedirectResponse
    {
        abort_unless($token->user_id === $request->user()->id, 404);
        $token->delete();
        AuditLog::record('api_token.revoked', null, $token, ['name' => $token->name]);

        return back();
    }
}
