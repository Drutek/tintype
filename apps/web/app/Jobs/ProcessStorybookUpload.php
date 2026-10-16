<?php

namespace App\Jobs;

use App\Models\Build;
use App\Services\BuildService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessStorybookUpload implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public int $buildId) {}

    public function handle(BuildService $builds): void
    {
        $build = Build::with('project')->find($this->buildId);
        if ($build) {
            $builds->processUpload($build);
        }
    }
}
