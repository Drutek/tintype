<?php

namespace App\Services\Scm;

use App\Models\Build;
use Illuminate\Support\Facades\Http;

/**
 * GitLab commit statuses. Project settings: scm=gitlab, scm_repository
 * (numeric id or group/project path), scm_token (project/group access token
 * with `api` scope), optional scm_url for self-managed GitLab.
 */
class GitLabReporter implements CommitStatusReporter
{
    public function supports(Build $build): bool
    {
        $p = $build->project;

        return $p->setting('scm') === 'gitlab' && $p->setting('scm_repository') && $p->scmToken();
    }

    public function report(Build $build): void
    {
        $p = $build->project;
        $status = StatusPresenter::present($build);
        $base = rtrim((string) ($p->setting('scm_url') ?: 'https://gitlab.com'), '/');
        $repo = rawurlencode((string) $p->setting('scm_repository'));
        Http::withToken((string) $p->scmToken())->timeout(20)
            ->post("{$base}/api/v4/projects/{$repo}/statuses/{$build->commit_sha}", [
                'state' => match ($status['state']) {
                    'error' => 'failed',
                    'failure' => 'failed',
                    default => $status['state'],
                },
                'name' => (string) config('tintype.github.check_name'),
                'target_url' => $build->webUrl(),
                'description' => $status['description'],
                'ref' => $build->branch,
            ])->throw();
    }
}
