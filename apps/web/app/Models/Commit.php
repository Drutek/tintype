<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $project_id
 * @property string $sha
 * @property list<string> $parents
 */
class Commit extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['parents' => 'array', 'committed_at' => 'datetime'];
    }
}
