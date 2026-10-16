<?php

namespace App\Jobs;

use App\Models\PullRequest;
use App\Services\GitHub\CheckRunReporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ReportPullRequestToGitHub implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $pullRequestId) {}

    public function handle(CheckRunReporter $reporter): void
    {
        $pr = PullRequest::with('project.organization')->find($this->pullRequestId);
        if ($pr) {
            $reporter->reportPullRequest($pr);
        }
    }
}
