<?php

namespace App\Services\GitHub;

use App\Models\Build;
use App\Models\Project;
use App\Models\PullRequest;

/**
 * Remembers pull requests (head/base branches, merge commit) from webhooks so
 * builds can be associated with PRs and squash/rebase merges can find the PR
 * head build as their baseline.
 */
class PullRequestTracker
{
    /** @param array<string, mixed> $payload */
    public function record(array $payload): void
    {
        $pr = $payload['pull_request'] ?? null;
        $repo = $payload['repository']['full_name'] ?? null;
        if (! $pr || ! $repo) {
            return;
        }
        foreach (Project::where('github_repository', $repo)->get() as $project) {
            PullRequest::updateOrCreate(
                ['project_id' => $project->id, 'number' => $pr['number']],
                [
                    'title' => mb_substr((string) ($pr['title'] ?? ''), 0, 255),
                    'state' => ($pr['merged'] ?? false) ? 'merged' : (string) ($pr['state'] ?? 'open'),
                    'head_branch' => $pr['head']['ref'] ?? null,
                    'head_sha' => $pr['head']['sha'] ?? null,
                    'base_branch' => $pr['base']['ref'] ?? null,
                    'merge_commit_sha' => $pr['merge_commit_sha'] ?? null,
                    'author' => $pr['user']['login'] ?? null,
                    'url' => $pr['html_url'] ?? null,
                ],
            );
            if (! empty($pr['head']['ref'])) {
                Build::where('project_id', $project->id)->where('branch', $pr['head']['ref'])->whereNull('pr_number')
                    ->update(['pr_number' => $pr['number']]);
            }
        }
    }
}
