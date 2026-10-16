<?php

namespace App\Services\GitHub;

use App\Enums\BuildStatus;
use App\Models\Build;
use App\Models\PullRequest;
use App\Services\UiReview;
use Illuminate\Support\Facades\Log;

/** Mirrors build state onto a GitHub check run ("Visual Tests"). */
class CheckRunReporter
{
    public function __construct(private readonly GitHubApp $app, private readonly UiReview $uiReview) {}

    public function report(Build $build): void
    {
        $project = $build->project;
        if (! $this->app->configured() || ! $project->github_repository) {
            return;
        }
        $installation = $project->github_installation_id ?? $this->app->installationForRepository($project->github_repository);
        if (! $installation) {
            Log::warning("Tintype GitHub App is not installed on {$project->github_repository}");

            return;
        }
        if ($project->github_installation_id !== $installation) {
            $project->forceFill(['github_installation_id' => $installation])->save();
        }

        $body = $this->payload($build);
        $http = $this->app->asInstallation($installation);
        $repo = $project->github_repository;
        if ($build->github_check_run_id) {
            GitHubApp::ensureOk($http->patch($this->app->apiUrl("repos/{$repo}/check-runs/{$build->github_check_run_id}"), $body), 'update check run');

            return;
        }
        $res = GitHubApp::ensureOk($http->post($this->app->apiUrl("repos/{$repo}/check-runs"), $body + ['head_sha' => $build->commit_sha]), 'create check run');
        $build->forceFill(['github_check_run_id' => $res->json('id')])->saveQuietly();
    }

    /** @return array<string, mixed> */
    public function payload(Build $build): array
    {
        $name = (string) config('tintype.github.check_name');
        $base = ['name' => $name, 'details_url' => $build->webUrl(), 'external_id' => (string) $build->id];
        if ($build->status->isInProgress()) {
            return $base + [
                'status' => 'in_progress',
                'output' => ['title' => "Build {$build->number} is capturing", 'summary' => 'Capturing snapshots…'],
            ];
        }
        $review = $build->changed_count + $build->new_count;
        [$conclusion, $title] = match ($build->status) {
            BuildStatus::Passed => ['success', 'No visual changes'],
            BuildStatus::Accepted => ['success', 'All changes accepted'],
            BuildStatus::Pending => ['action_required', "{$review} ".($review === 1 ? 'change needs' : 'changes need').' review'],
            BuildStatus::Denied => ['failure', 'Changes denied'],
            BuildStatus::Broken => ['failure', "{$build->error_count} ".($build->error_count === 1 ? 'story' : 'stories').' errored'],
            BuildStatus::Canceled => ['cancelled', 'Build canceled'],
            default => ['failure', 'Build failed'],
        };
        $summary = sprintf(
            "**%d** snapshots · **%d** changed · **%d** new · **%d** errors · **%d** unchanged\n\n[Review build %d](%s)",
            $build->snapshot_count, $build->changed_count, $build->new_count, $build->error_count, $build->unchanged_count, $build->number, $build->webUrl(),
        );
        if ($build->error) {
            $summary .= "\n\n> {$build->error}";
        }

        return $base + [
            'status' => 'completed',
            'conclusion' => $conclusion,
            'completed_at' => ($build->completed_at ?? now())->toIso8601String(),
            'output' => ['title' => $title, 'summary' => $summary],
        ];
    }

    /** Second check ("UI Review") on the PR head commit, driven by PR-level approval. */
    public function reportPullRequest(PullRequest $pr): void
    {
        $project = $pr->project;
        if (! $this->app->configured() || ! $project->github_repository || ! $project->setting('ui_review')) {
            return;
        }
        $cmp = $this->uiReview->compare($project, $pr, $pr->number);
        $head = $cmp['head'];
        $installation = $project->github_installation_id ?? $this->app->installationForRepository($project->github_repository);
        if (! $head || ! $installation) {
            return;
        }
        $count = count($cmp['changes']);
        [$conclusion, $title] = match (true) {
            $pr->review_status === 'approved' => ['success', 'UI changes approved'],
            $pr->review_status === 'changes_requested' => ['failure', 'Changes requested'],
            $count === 0 => ['success', 'No UI changes'],
            default => ['action_required', "{$count} UI ".($count === 1 ? 'change' : 'changes').' to review'],
        };
        $url = route('pulls.show', [$project->organization, $project, $pr->number]);
        $body = [
            'name' => (string) config('tintype.github.ui_review_check_name'),
            'head_sha' => $head->commit_sha,
            'status' => 'completed',
            'conclusion' => $conclusion,
            'details_url' => $url,
            'output' => ['title' => $title, 'summary' => "[Open UI Review]({$url})"],
        ];
        $res = GitHubApp::ensureOk($this->app->asInstallation($installation)->post($this->app->apiUrl("repos/{$project->github_repository}/check-runs"), $body), 'create UI review check');
        $pr->forceFill(['ui_review_check_run_id' => $res->json('id')])->save();
    }
}
