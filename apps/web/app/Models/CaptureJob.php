<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $build_id
 * @property string $browser
 * @property list<string> $story_ids
 * @property string $status
 * @property string|null $worker_id
 * @property Carbon|null $lease_expires_at
 * @property int $attempts
 * @property string|null $error
 * @property-read Build $build
 */
class CaptureJob extends Model
{
    public const MAX_ATTEMPTS = 3;

    public const LEASE_SECONDS = 120;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['story_ids' => 'array', 'lease_expires_at' => 'datetime'];
    }

    /** @return BelongsTo<Build, $this> */
    public function build(): BelongsTo
    {
        return $this->belongsTo(Build::class);
    }
}
