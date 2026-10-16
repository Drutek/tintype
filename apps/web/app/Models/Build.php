<?php

namespace App\Models;

use App\Enums\BuildStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $project_id
 * @property int $number
 * @property string $commit_sha
 * @property string $branch
 * @property int|null $pr_number
 * @property BuildStatus $status
 * @property list<int>|null $baseline_build_ids
 * @property string|null $baseline_strategy
 * @property list<string> $browsers
 * @property string|null $capture_image
 * @property array<string, mixed>|null $ci
 * @property array<string, mixed>|null $options
 * @property int|null $github_check_run_id
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon $created_at
 * @property-read Project $project
 */
class Build extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => BuildStatus::class,
            'baseline_build_ids' => 'array',
            'browsers' => 'array',
            'ci' => 'array',
            'options' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
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

    /** @return HasMany<CaptureJob, $this> */
    public function captureJobs(): HasMany
    {
        return $this->hasMany(CaptureJob::class);
    }

    /** @return HasMany<Comment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    public function storybookPath(): string
    {
        return "storybooks/{$this->id}";
    }

    public function storybookArchivePath(): string
    {
        return "uploads/build-{$this->id}.zip";
    }

    /** Secret path segment that lets capture workers load the Storybook without a session. */
    public function storybookToken(): string
    {
        return substr(hash_hmac('sha256', 'storybook:'.$this->id, (string) config('app.key')), 0, 32);
    }

    /** Storybook URL as seen by capture workers (may be a private address). */
    public function internalStorybookUrl(): string
    {
        return self::internalUrl("/storybooks/{$this->id}/{$this->storybookToken()}/");
    }

    public static function internalUrl(string $path): string
    {
        $base = config('tintype.internal_url');
        if (! is_string($base) || $base === '') {
            return rtrim(url('/'), '/').$path;
        }

        return rtrim($base, '/').$path;
    }

    public function storybookUrl(): string
    {
        // url() strips trailing slashes; the slash matters for relative asset URLs.
        return url("/storybooks/{$this->id}/{$this->storybookToken()}").'/';
    }

    public function webUrl(): string
    {
        $this->loadMissing('project.organization');

        return route('builds.show', [$this->project->organization, $this->project, $this->number]);
    }

    public function getRouteKeyName(): string
    {
        return 'number';
    }
}
