<?php

namespace App\Jobs;

use App\Models\Build;
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
        if ($build) {
            $reporter->report($build);
        }
    }
}
