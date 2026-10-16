<?php

namespace App\Jobs;

use App\Http\Controllers\Api\BuildController;
use App\Models\Build;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/** Posts build status changes to the project's webhook and/or Slack. */
class SendBuildNotifications implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    public function __construct(public int $buildId) {}

    public function handle(): void
    {
        $build = Build::with('project.organization')->find($this->buildId);
        if (! $build || $build->status->isInProgress()) {
            return;
        }
        $options = $build->options ?? [];
        if (($options['notifiedStatus'] ?? null) === $build->status->value) {
            return;
        }
        $project = $build->project;
        $summary = BuildController::summary($build);
        $webhook = $project->setting('notify_webhook_url');
        if (is_string($webhook) && $webhook !== '') {
            $body = json_encode(['event' => 'build.status', 'project' => ['name' => $project->name, 'slug' => $project->slug, 'organization' => $project->organization->slug], 'build' => $summary], JSON_THROW_ON_ERROR);
            $res = Http::timeout(10)->withBody($body, 'application/json')->withHeaders([
                'X-Tintype-Event' => 'build.status',
                'X-Tintype-Signature' => 'sha256='.hash_hmac('sha256', $body, $project->token_hash),
            ])->post($webhook);
            if ($res->failed()) {
                Log::warning("Tintype webhook to {$webhook} failed: {$res->status()}");
                $res->throw();
            }
        }
        $slack = $project->setting('notify_slack_webhook_url');
        if (is_string($slack) && $slack !== '') {
            Http::timeout(10)->post($slack, ['text' => $this->slackText($build, $summary)])->throw();
        }
        $build->forceFill(['options' => array_merge($options, ['notifiedStatus' => $build->status->value])])->saveQuietly();
    }

    /** @param array<string, mixed> $summary */
    private function slackText(Build $build, array $summary): string
    {
        $emoji = match ($build->status->exitCode()) {
            0 => ':white_check_mark:',
            1 => ':eyes:',
            default => ':x:',
        };
        $c = $summary['counts'];

        return sprintf(
            '%s *%s* build <%s|#%d> on `%s` is *%s* — %d changed, %d new, %d errors',
            $emoji, $build->project->name, $summary['url'], $build->number, $build->branch, $build->status->value,
            $c['changed'], $c['new'], $c['errors'],
        );
    }
}
