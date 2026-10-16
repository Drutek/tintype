<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $worker_id
 * @property string|null $arch
 * @property string|null $image
 * @property list<string>|null $browsers
 * @property string|null $browser_version
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Worker extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['browsers' => 'array', 'last_seen_at' => 'datetime'];
    }
}
