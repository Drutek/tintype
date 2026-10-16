<?php

namespace App\Models;

use App\Enums\Role;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 */
class Organization extends Model
{
    use HasFactory;

    protected $guarded = [];

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
    }

    /** @return HasMany<Project, $this> */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function roleOf(?User $user): ?Role
    {
        if (! $user) {
            return null;
        }
        $role = $this->users()->whereKey($user->id)->first()?->pivot?->getAttribute('role');

        return $role ? Role::from($role) : null;
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
