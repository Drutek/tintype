<?php

namespace App\Services\Scm;

use App\Models\Build;
use Illuminate\Support\Facades\Http;

/**
 * Bitbucket Cloud build statuses. Project settings: scm=bitbucket,
 * scm_repository (workspace/repo_slug), scm_token (repository access token).
 */
class BitbucketReporter implements CommitStatusReporter
{
    public function supports(Build $build): bool
    {
        $p = $build->project;

        return $p->setting('scm') === 'bitbucket' && $p->setting('scm_repository') && $p->scmToken();
    }

    public function report(Build $build): void
    {
        $p = $build->project;
        $status = StatusPresenter::present($build);
        $base = rtrim((string) ($p->setting('scm_url') ?: 'https://api.bitbucket.org'), '/');
        Http::withToken((string) $p->scmToken())->timeout(20)
            ->post("{$base}/2.0/repositories/{$p->setting('scm_repository')}/commit/{$build->commit_sha}/statuses/build", [
                'key' => 'tintype-'.$p->slug,
                'name' => (string) config('tintype.github.check_name'),
                'state' => match ($status['state']) {
                    'pending' => 'INPROGRESS',
                    'success' => 'SUCCESSFUL',
                    default => 'FAILED',
                },
                'url' => $build->webUrl(),
                'description' => $status['description'],
            ])->throw();
    }
}
