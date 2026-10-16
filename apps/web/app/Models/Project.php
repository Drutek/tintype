<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $organization_id
 * @property string $name
 * @property string $slug
 * @property string $token_prefix
 * @property string $token_hash
 * @property string $default_branch
 * @property string|null $github_repository
 * @property int|null $github_installation_id
 * @property array<string, mixed>|null $settings
 * @property-read Organization $organization
 */
class Project extends Model
{
    protected $guarded = [];

    protected $hidden = ['token_hash'];

    public const DEFAULT_SETTINGS = [
        'browsers' => ['chromium'],
        'diff_threshold' => 0.063,
        'include_anti_aliasing' => false,
        'allowed_hosts' => [],
        'fixed_time' => '2024-01-01T00:00:00.000Z',
        'device_scale_factor' => 1,
        'viewport_width' => 1200,
        'viewport_height' => 900,
        'auto_accept_default_branch' => false,
        'retention_days' => 90,
        'storybook_public' => false,
        'ui_review' => true,
        'rebaseline_pending' => false,
        'notify_webhook_url' => null,
        'notify_slack_webhook_url' => null,
        'a11y' => true,
        'scm' => 'github', // github | gitlab | bitbucket
        'scm_repository' => null,
        'scm_url' => null,
    ];

    protected function casts(): array
    {
        return ['settings' => 'array'];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return HasMany<Build, $this> */
    public function builds(): HasMany
    {
        return $this->hasMany(Build::class);
    }

    /** @return HasMany<Story, $this> */
    public function stories(): HasMany
    {
        return $this->hasMany(Story::class);
    }

    /** @return HasMany<Commit, $this> */
    public function commits(): HasMany
    {
        return $this->hasMany(Commit::class);
    }

    public function setting(string $key): mixed
    {
        return ($this->settings ?? [])[$key] ?? self::DEFAULT_SETTINGS[$key] ?? null;
    }

    /** @return array<string, mixed> */
    public function resolvedSettings(): array
    {
        return array_merge(self::DEFAULT_SETTINGS, $this->settings ?? []);
    }

    /** Encrypted access token for GitLab/Bitbucket status reporting. */
    public function scmToken(): ?string
    {
        $value = $this->settings['scm_token_encrypted'] ?? null;

        return is_string($value) && $value !== '' ? decrypt($value) : null;
    }

    public function setScmToken(?string $token): void
    {
        $this->settings = array_merge($this->settings ?? [], ['scm_token_encrypted' => $token ? encrypt($token) : null]);
    }

    /**
     * Settings safe to show in the UI (secrets removed).
     *
     * @return array<string, mixed>
     */
    public function publicSettings(): array
    {
        $s = $this->resolvedSettings();
        $s['scm_token_set'] = ! empty($s['scm_token_encrypted']);
        unset($s['scm_token_encrypted']);

        return $s;
    }

    /** Generate a new project token; returns the plaintext once. */
    public function rotateToken(): string
    {
        $prefix = 'tt_'.Str::lower(Str::random(8));
        $token = $prefix.'_'.Str::random(32);
        $this->token_prefix = $prefix;
        $this->token_hash = hash('sha256', $token);
        $this->save();

        return $token;
    }

    public static function findByToken(?string $token): ?self
    {
        if (! $token || ! preg_match('/^(tt_[a-z0-9]{8})_/', $token, $m)) {
            return null;
        }
        $project = self::where('token_prefix', $m[1])->first();

        return $project && hash_equals($project->token_hash, hash('sha256', $token)) ? $project : null;
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
