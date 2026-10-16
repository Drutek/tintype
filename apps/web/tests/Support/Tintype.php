<?php

namespace Tests\Support;

use App\Enums\BuildStatus;
use App\Enums\ReviewState;
use App\Enums\Role;
use App\Enums\SnapshotStatus;
use App\Models\Build;
use App\Models\Commit;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Snapshot;
use App\Models\Story;
use App\Models\User;
use ZipArchive;

/** Helpers to build projects, commit graphs, builds and PNGs in tests. */
trait Tintype
{
    protected string $projectToken = '';

    protected function makeProject(array $attrs = []): Project
    {
        $org = Organization::create(['name' => 'Acme', 'slug' => 'acme-'.uniqid()]);
        $project = new Project(array_merge([
            'organization_id' => $org->id, 'name' => 'Web', 'slug' => 'web', 'default_branch' => 'main',
            'token_prefix' => '', 'token_hash' => '',
        ], $attrs));
        $this->projectToken = $project->rotateToken();

        return $project;
    }

    protected function member(Project $project, Role $role = Role::Owner): User
    {
        $user = User::factory()->create();
        $project->organization->users()->attach($user->id, ['role' => $role->value]);

        return $user;
    }

    /** @param array<string, list<string>> $graph sha => parents */
    protected function commits(Project $project, array $graph): void
    {
        foreach ($graph as $sha => $parents) {
            Commit::updateOrCreate(['project_id' => $project->id, 'sha' => $sha], ['parents' => $parents]);
        }
    }

    protected function story(Project $project, string $id): Story
    {
        return Story::firstOrCreate(['project_id' => $project->id, 'story_id' => $id], ['title' => 'T', 'name' => $id]);
    }

    /**
     * Completed build with snapshots: ['story-id' => ['sha' => ..., 'status' => ..., 'review' => ...]].
     *
     * @param  array<string, array<string, mixed>>  $snaps
     */
    protected function completedBuild(Project $project, string $commit, string $branch, array $snaps = [], BuildStatus $status = BuildStatus::Passed, array $baselineIds = []): Build
    {
        $number = (int) Build::where('project_id', $project->id)->max('number') + 1;
        $build = Build::create([
            'project_id' => $project->id, 'number' => $number, 'commit_sha' => $commit, 'branch' => $branch,
            'status' => $status, 'browsers' => ['chromium'], 'baseline_build_ids' => $baselineIds,
        ]);
        foreach ($snaps as $storyId => $s) {
            Snapshot::create([
                'build_id' => $build->id,
                'story_id' => $this->story($project, $storyId)->id,
                'browser' => 'chromium',
                'mode_key' => $s['mode'] ?? 'default',
                'status' => $s['status'] ?? SnapshotStatus::Unchanged,
                'review_state' => $s['review'] ?? null,
                'image_sha' => $s['sha'] ?? null,
                'baseline_snapshot_id' => $s['baseline'] ?? null,
            ]);
        }

        return $build;
    }

    protected function snap(Build $build, string $storyId): Snapshot
    {
        return Snapshot::where('build_id', $build->id)->whereHas('story', fn ($q) => $q->where('story_id', $storyId))->firstOrFail();
    }

    /** A solid-colour PNG (GD). */
    protected function png(int $w = 10, int $h = 10, array $rgb = [255, 255, 255]): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, ...$rgb));
        ob_start();
        imagepng($img);

        return (string) ob_get_clean();
    }

    /** Minimal static Storybook zip with the given story ids. */
    protected function storybookZip(array $storyIds): string
    {
        $path = tempnam(sys_get_temp_dir(), 'sb').'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        $entries = [];
        foreach ($storyIds as $id) {
            [$title, $name] = array_pad(explode('--', $id, 2), 2, $id);
            $entries[$id] = ['type' => 'story', 'id' => $id, 'title' => ucfirst($title), 'name' => ucfirst($name), 'importPath' => "./src/{$title}.stories.tsx"];
        }
        $entries['intro--docs'] = ['type' => 'docs', 'id' => 'intro--docs', 'title' => 'Intro', 'name' => 'Docs', 'importPath' => './intro.mdx'];
        $zip->addFromString('index.json', json_encode(['v' => 5, 'entries' => $entries]));
        $zip->addFromString('iframe.html', '<html><body><div id="storybook-root"></div></body></html>');
        $zip->addFromString('assets/app.js', 'console.log(1)');
        $zip->close();
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }
}
