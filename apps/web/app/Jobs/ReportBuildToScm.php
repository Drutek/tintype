<?php

namespace App\Jobs;

use App\Models\Build;
use App\Services\Scm\BitbucketReporter;
use App\Services\Scm\GitLabReporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Commit statuses for non-GitHub providers (GitHub uses check runs via the App). */
class ReportBuildToScm implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 30, 120];

    public function __construct(public int $buildId) {}

    public function handle(GitLabReporter $gitlab, BitbucketReporter $bitbucket): void
    {
        $build = Build::with('project.organization')->find($this->buildId);
        if (! $build) {
            return;
        }
        foreach ([$gitlab, $bitbucket] as $reporter) {
            if ($reporter->supports($build)) {
                $reporter->report($build);
            }
        }
    }
}
