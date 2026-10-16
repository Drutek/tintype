<?php

namespace App\Console\Commands;

use App\Models\CaptureJob;
use Aws\CloudWatch\CloudWatchClient;
use Illuminate\Console\Command;

class PublishMetrics extends Command
{
    protected $signature = 'tintype:metrics {--print : print instead of publishing}';

    protected $description = 'Publish queued capture shards per browser to CloudWatch (for worker autoscaling)';

    public function handle(): int
    {
        $queued = [];
        foreach (['chromium', 'firefox', 'webkit'] as $browser) {
            // CloudWatch metric values are doubles.
            $queued[$browser] = (float) CaptureJob::where('browser', $browser)->where('status', 'queued')->count();
        }
        $namespace = config('tintype.cloudwatch_namespace');
        if ($this->option('print') || ! is_string($namespace) || $namespace === '') {
            foreach ($queued as $browser => $count) {
                $this->line("QueuedCaptureJobs{Browser={$browser}} {$count}");
            }

            return self::SUCCESS;
        }

        $region = config('filesystems.disks.tintype.region');
        $client = new CloudWatchClient(['version' => 'latest', 'region' => is_string($region) && $region !== '' ? $region : 'us-east-1']);
        $client->putMetricData([
            'Namespace' => $namespace,
            'MetricData' => array_map(fn (string $browser, float $count) => [
                'MetricName' => 'QueuedCaptureJobs',
                'Dimensions' => [['Name' => 'Browser', 'Value' => $browser]],
                'Value' => $count,
                'Unit' => 'Count',
            ], array_keys($queued), array_values($queued)),
        ]);

        return self::SUCCESS;
    }
}
