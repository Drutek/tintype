<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\CaptureJob;
use App\Models\GithubInstallation;
use App\Models\Worker;
use App\Services\GitHub\GitHubApp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class AdminController extends Controller
{
    private function admin(Request $request): void
    {
        abort_unless($request->user()?->is_admin, 403);
    }

    public function index(Request $request, GitHubApp $github): Response
    {
        $this->admin($request);
        $state = Str::random(32);
        $request->session()->put('github_manifest_state', $state);
        $creds = $github->credentials();

        return Inertia::render('admin/index', [
            'github' => [
                'configured' => $creds !== null,
                'name' => $creds['name'] ?? null,
                'slug' => $creds['slug'] ?? null,
                'htmlUrl' => $creds['html_url'] ?? null,
                'installUrl' => $github->installUrl(),
                'manifest' => json_encode($github->manifest()),
                'createUrl' => rtrim((string) config('tintype.github.web_url'), '/').'/settings/apps/new?state='.$state,
                'createOrgUrlTemplate' => rtrim((string) config('tintype.github.web_url'), '/').'/organizations/{org}/settings/apps/new?state='.$state,
                'installations' => GithubInstallation::orderBy('account_login')->get(['installation_id', 'account_login', 'account_type']),
                'appUrl' => config('app.url'),
            ],
            'workers' => Worker::orderByDesc('last_seen_at')->limit(100)->get()->map(fn (Worker $w) => [
                'workerId' => $w->worker_id,
                'arch' => $w->arch,
                'image' => $w->image,
                'browsers' => $w->browsers,
                'browserVersion' => $w->browser_version,
                'lastSeenAt' => $w->last_seen_at?->toIso8601String(),
                'online' => $w->last_seen_at && $w->last_seen_at->gt(now()->subMinutes(2)),
            ]),
            'queue' => [
                'queued' => CaptureJob::where('status', 'queued')->count(),
                'leased' => CaptureJob::where('status', 'leased')->count(),
                'failed24h' => CaptureJob::where('status', 'failed')->where('updated_at', '>', now()->subDay())->count(),
            ],
            'workerTokenConfigured' => (string) config('tintype.worker_token') !== '',
        ]);
    }

    public function githubCallback(Request $request, GitHubApp $github): RedirectResponse
    {
        $this->admin($request);
        $expected = (string) $request->session()->pull('github_manifest_state');
        abort_unless($expected !== '' && hash_equals($expected, (string) $request->query('state')), 403, 'Invalid state');
        try {
            $github->completeManifest((string) $request->query('code'));
        } catch (Throwable $e) {
            return redirect()->route('admin.index')->withErrors(['github' => 'Could not create the GitHub App: '.$e->getMessage()]);
        }

        return redirect()->route('admin.index');
    }

    public function githubForget(Request $request): RedirectResponse
    {
        $this->admin($request);
        AppSetting::whereKey('github_app')->delete();

        return back();
    }
}
