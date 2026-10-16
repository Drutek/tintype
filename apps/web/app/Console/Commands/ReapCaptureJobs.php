<?php

namespace App\Console\Commands;

use App\Services\CaptureService;
use Illuminate\Console\Command;

class ReapCaptureJobs extends Command
{
    protected $signature = 'tintype:reap';

    protected $description = 'Requeue capture jobs whose worker lease expired (fail after max attempts)';

    public function handle(CaptureService $capture): int
    {
        $capture->reapExpired();

        return self::SUCCESS;
    }
}
