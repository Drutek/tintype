<?php

namespace App\Services;

use App\Enums\ReviewState;
use App\Jobs\ReportPullRequestToGitHub;
use App\Models\AuditLog;
use App\Models\Build;
use App\Models\Project;
use App\Models\PullRequest;
use App\Models\Snapshot;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

/** Records a UI Review decision on a pull request (web UI and MCP). */
class PullRequestReviewer
{
    public function __construct(private readonly UiReview $ui, private readonly ReviewService $decisions) {}

    /**
     * @param  'approved'|'changes_requested'|'commented'  $state
     * @return int snapshots accepted alongside the approval
     *
     * @throws InvalidArgumentException when the head build cannot take decisions
     */
    public function review(Project $project, int $number, User $user, string $state, ?string $body = null, bool $acceptPending = false, string $source = 'ui_review'): int
    {
        $pr = PullRequest::firstOrCreate(['project_id' => $project->id, 'number' => $number], [
            'head_branch' => Build::where('project_id', $project->id)->where('pr_number', $number)->latest('id')->value('branch'),
            'base_branch' => $project->default_branch,
        ]);
        $pr->reviews()->create(['user_id' => $user->id, 'state' => $state, 'body' => $body]);
        if ($state !== 'commented') {
            $pr->update(['review_status' => $state, 'review_fingerprint' => $this->ui->fingerprint($this->ui->compare($project, $pr, $number)['changes'])]);
        }
        $count = 0;
        // "Approve & accept all": also accept the head build's pending snapshots, so the
        // Visual Tests check clears along with UI Review.
        if ($state === 'approved' && $acceptPending && $head = $this->ui->compare($project, $pr, $number)['head']) {
            $count = $this->decisions->decide($head, self::pendingIn($head)->get(), 'accepted', $user, $source);
            if ($count) {
                AuditLog::record('build.bulk_accepted', $project->organization, $head, ['count' => $count, 'scope' => 'pull_request']);
            }
        }
        ReportPullRequestToGitHub::dispatch($pr->id);

        return $count;
    }

    /** @return HasMany<Snapshot, Build> */
    public static function pendingIn(Build $build): HasMany
    {
        return $build->snapshots()->where('review_state', ReviewState::Pending->value);
    }
}
