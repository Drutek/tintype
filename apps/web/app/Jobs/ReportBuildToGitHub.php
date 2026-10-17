<?php

namespace App\Jobs;

use App\Models\Build;
use App\Models\PullRequest;
use App\Services\GitHub\CheckRunReporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ReportBuildToGitHub implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 30, 120];

    public function __construct(public int $buildId) {}

    public function handle(CheckRunReporter $reporter): void
    {
        $build = Build::with('project.organization')->find($this->buildId);
        if (! $build) {
            return;
        }
        $reporter->report($build);
        // A finished PR build also refreshes UI Review, so the PR shows both checks
        // before anyone has reviewed it.
        if ($build->pr_number && ! $build->status->isInProgress()) {
            $pr = PullRequest::firstOrCreate(
                ['project_id' => $build->project_id, 'number' => $build->pr_number],
                ['head_branch' => $build->branch, 'base_branch' => $build->project->default_branch],
            );
            $reporter->reportPullRequest($pr->setRelation('project', $build->project));
        }
    }
}
