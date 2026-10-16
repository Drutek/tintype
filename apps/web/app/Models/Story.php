<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $project_id
 * @property string $story_id
 * @property string $title
 * @property string $name
 * @property string|null $import_path
 * @property list<string>|null $tags
 */
class Story extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['tags' => 'array'];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return HasMany<Snapshot, $this> */
    public function snapshots(): HasMany
    {
        return $this->hasMany(Snapshot::class);
    }
}
