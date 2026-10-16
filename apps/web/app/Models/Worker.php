<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Worker extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['browsers' => 'array', 'last_seen_at' => 'datetime'];
    }
}
