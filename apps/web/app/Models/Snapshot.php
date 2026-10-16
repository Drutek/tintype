<?php

namespace App\Models;

use App\Enums\ReviewState;
use App\Enums\SnapshotStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $build_id
 * @property int $story_id
 * @property string $browser
 * @property string $mode_key
 * @property string $mode_name
 * @property int|null $viewport_width
 * @property int|null $viewport_height
 * @property SnapshotStatus $status
 * @property ReviewState|null $review_state
 * @property string|null $image_sha
 * @property int|null $width
 * @property int|null $height
 * @property int|null $baseline_snapshot_id
 * @property string|null $diff_sha
 * @property float|null $diff_ratio
 * @property list<array{x:int,y:int,width:int,height:int}>|null $diff_regions
 * @property list<array{x:int,y:int,width:int,height:int}>|null $ignore_regions
 * @property array<string, mixed>|null $params
 * @property string|null $error
 * @property int|null $duration_ms
 * @property list<array{id: string, impact: string|null, help: string, helpUrl: string, nodes: int, targets: list<string>}>|null $a11y
 * @property int $a11y_new_count
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property-read Build $build
 * @property-read Story $story
 * @property-read Snapshot|null $baseline
 */
class Snapshot extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => SnapshotStatus::class,
            'review_state' => ReviewState::class,
            'diff_regions' => 'array',
            'ignore_regions' => 'array',
            'params' => 'array',
            'a11y' => 'array',
            'diff_ratio' => 'float',
            'reviewed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Build, $this> */
    public function build(): BelongsTo
    {
        return $this->belongsTo(Build::class);
    }

    /** @return BelongsTo<Story, $this> */
    public function story(): BelongsTo
    {
        return $this->belongsTo(Story::class);
    }

    /** @return BelongsTo<Snapshot, $this> */
    public function baseline(): BelongsTo
    {
        return $this->belongsTo(Snapshot::class, 'baseline_snapshot_id');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** @return HasMany<Review, $this> */
    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    /** @return HasMany<Comment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    public function key(): string
    {
        return self::makeKey($this->story_id, $this->browser, $this->mode_key);
    }

    public static function makeKey(int $storyId, string $browser, string $modeKey): string
    {
        return "{$storyId}|{$browser}|{$modeKey}";
    }

    /**
     * Whether this snapshot can serve as a baseline for descendants: its image
     * is approved (explicitly, or because it matched an approved baseline).
     */
    public function isApproved(): bool
    {
        return match ($this->status) {
            SnapshotStatus::Unchanged => true,
            SnapshotStatus::New, SnapshotStatus::Changed => $this->review_state === ReviewState::Accepted,
            default => false,
        };
    }
}
