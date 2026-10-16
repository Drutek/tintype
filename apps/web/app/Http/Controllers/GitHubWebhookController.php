<?php

namespace App\Http\Controllers;

use App\Jobs\ReportBuildToGitHub;
use App\Models\Build;
use App\Models\GithubInstallation;
use App\Models\Project;
use App\Services\GitHub\GitHubApp;
use App\Services\GitHub\PullRequestTracker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GitHubWebhookController extends Controller
{
    public function __invoke(Request $request, GitHubApp $github, PullRequestTracker $prs): JsonResponse
    {
        if (! $github->verifyWebhook($request->getContent(), $request->header('X-Hub-Signature-256'))) {
            return response()->json(['message' => 'Bad signature'], 401);
        }
        $event = (string) $request->header('X-GitHub-Event');
        $payload = $request->json()->all();

        switch ($event) {
            case 'installation':
                $inst = $payload['installation'] ?? [];
                if (($payload['action'] ?? '') === 'deleted') {
                    GithubInstallation::where('installation_id', $inst['id'] ?? 0)->delete();
                    Project::where('github_installation_id', $inst['id'] ?? 0)->update(['github_installation_id' => null]);
                } else {
                    GithubInstallation::updateOrCreate(['installation_id' => $inst['id']], [
                        'account_login' => $inst['account']['login'] ?? 'unknown',
                        'account_type' => $inst['account']['type'] ?? null,
                    ]);
                }
                break;
            case 'pull_request':
                $prs->record($payload);
                break;
            case 'check_run':
                if (($payload['action'] ?? '') === 'rerequested') {
                    $build = Build::where('github_check_run_id', $payload['check_run']['id'] ?? 0)->first();
                    if ($build) {
                        ReportBuildToGitHub::dispatch($build->id);
                    }
                }
                break;
        }

        return response()->json(['ok' => true]);
    }
}
