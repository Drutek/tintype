<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $project_id
 * @property int $number
 * @property string|null $title
 * @property string $state
 * @property string|null $head_branch
 * @property string|null $base_branch
 * @property string|null $merge_commit_sha
 * @property string $review_status
 * @property int|null $ui_review_check_run_id
 */
class PullRequest extends Model
{
    protected $guarded = [];

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return HasMany<PullRequestReview, $this> */
    public function reviews(): HasMany
    {
        return $this->hasMany(PullRequestReview::class);
    }
}
