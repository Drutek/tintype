<?php

namespace App\Services;

use App\Enums\BuildStatus;
use App\Events\BuildUpdated;
use App\Models\Build;
use App\Models\CaptureJob;
use App\Models\Commit;
use App\Models\Project;
use App\Models\Story;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

class BuildService
{
    public function __construct(
        private readonly BaselineResolver $baselines,
        private readonly ImageStore $images,
    ) {}

    /**
     * Create a build from the CLI payload and record the commit ancestry it sent.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(Project $project, array $data): Build
    {
        return DB::transaction(function () use ($project, $data) {
            foreach ($data['commits'] ?? [] as $c) {
                Commit::updateOrCreate(
                    ['project_id' => $project->id, 'sha' => $c['sha']],
                    [
                        'parents' => array_values($c['parents'] ?? []),
                        'message' => isset($c['message']) ? Str::limit((string) $c['message'], 990) : null,
                        'author' => $c['author'] ?? null,
                        'committed_at' => isset($c['committedAt']) ? Carbon::parse($c['committedAt']) : null,
                    ],
                );
            }
            Commit::firstOrCreate(['project_id' => $project->id, 'sha' => $data['commit']], ['parents' => []]);

            $number = (int) Build::where('project_id', $project->id)->lockForUpdate()->max('number') + 1;
            $options = Arr::only($data['options'] ?? [], ['autoAcceptChanges', 'onlyStoryNames', 'onlyChanged', 'externals', 'changedFiles', 'turboSnap']);

            return Build::create([
                'project_id' => $project->id,
                'number' => $number,
                'commit_sha' => $data['commit'],
                'branch' => $data['branch'],
                'pr_number' => $data['prNumber'] ?? null,
                'status' => BuildStatus::Uploading,
                'browsers' => $project->setting('browsers'),
                'cli_version' => $data['cliVersion'] ?? null,
                'ci' => $data['ci'] ?? null,
                'options' => $options,
                'started_at' => now(),
            ]);
        });
    }

    /**
     * Unpack the uploaded Storybook, index its stories, resolve ancestor builds
     * and queue capture shards.
     */
    public function processUpload(Build $build): void
    {
        $disk = $this->images->disk();
        $archive = $build->storybookArchivePath();
        if (! $disk->exists($archive)) {
            $this->fail($build, 'Storybook upload not found');

            return;
        }

        $tmpDir = storage_path('app/tmp/build-'.$build->id.'-'.Str::random(6));
        $tmpZip = $tmpDir.'.zip';
        File::ensureDirectoryExists(dirname($tmpZip));
        try {
            $in = $disk->readStream($archive);
            $out = fopen($tmpZip, 'wb');
            stream_copy_to_stream($in, $out);
            fclose($out);
            is_resource($in) && fclose($in);

            $zip = new ZipArchive;
            if ($zip->open($tmpZip) !== true) {
                throw new RuntimeException('Upload is not a valid zip archive');
            }
            // Reject path traversal before extracting.
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                if (str_contains($name, '..') || str_starts_with($name, '/')) {
                    throw new RuntimeException("Unsafe path in archive: {$name}");
                }
            }
            $zip->extractTo($tmpDir);
            $zip->close();

            if (! is_file("$tmpDir/index.json") || ! is_file("$tmpDir/iframe.html")) {
                throw new RuntimeException('Archive is not a static Storybook build (missing index.json or iframe.html)');
            }

            foreach (File::allFiles($tmpDir) as $file) {
                $disk->put($build->storybookPath().'/'.$file->getRelativePathname(), $file->getContents());
            }

            $index = json_decode((string) file_get_contents("$tmpDir/index.json"), true);
            $entries = $index['entries'] ?? $index['stories'] ?? null;
            if (! is_array($entries)) {
                throw new RuntimeException('index.json has no entries');
            }
            $this->ingestIndex($build, $entries);
        } catch (RuntimeException $e) {
            $this->fail($build, $e->getMessage());
        } finally {
            File::deleteDirectory($tmpDir);
            File::delete($tmpZip);
            $disk->delete($archive);
        }
    }

    /** @param array<string, array<string, mixed>> $entries */
    public function ingestIndex(Build $build, array $entries): void
    {
        $project = $build->project;
        $storyIds = [];
        foreach ($entries as $entry) {
            if (($entry['type'] ?? 'story') !== 'story') {
                continue;
            }
            $story = Story::updateOrCreate(
                ['project_id' => $project->id, 'story_id' => $entry['id']],
                [
                    'title' => $entry['title'] ?? $entry['id'],
                    'name' => $entry['name'] ?? $entry['id'],
                    'import_path' => $entry['importPath'] ?? null,
                    'component_path' => $entry['componentPath'] ?? null,
                    'tags' => $entry['tags'] ?? [],
                ],
            );
            $storyIds[$story->story_id] = $story;
        }

        $toCapture = array_values(array_filter(
            array_keys($storyIds),
            fn (string $id) => $this->shouldCapture($build, $storyIds[$id]),
        ));

        $ancestors = $this->baselines->ancestorBuilds($build);
        $build->forceFill([
            'story_count' => count($storyIds),
            'baseline_build_ids' => $ancestors['build_ids'],
            'baseline_strategy' => $ancestors['strategy'],
            'options' => array_merge($build->options ?? [], ['storyIds' => array_keys($storyIds)]),
        ])->save();

        if (! $storyIds) {
            $this->fail($build, 'No stories found in the Storybook index');

            return;
        }

        $size = max(1, (int) config('tintype.shard_size', 25));
        DB::transaction(function () use ($build, $toCapture, $size) {
            foreach ($build->browsers as $browser) {
                foreach (array_chunk($toCapture, $size) as $chunk) {
                    CaptureJob::create(['build_id' => $build->id, 'browser' => $browser, 'story_ids' => $chunk]);
                }
            }
            $build->update(['status' => BuildStatus::Queued]);
        });

        BuildUpdated::dispatch($build);

        if (! $toCapture) {
            app(BuildFinalizer::class)->finalize($build);
        }
    }

    private function shouldCapture(Build $build, Story $story): bool
    {
        $only = $build->options['onlyStoryNames'] ?? null;
        if ($only) {
            $matches = false;
            foreach ((array) $only as $pattern) {
                $re = '/^'.str_replace('\*', '.*', preg_quote((string) $pattern, '/')).'$/i';
                if (preg_match($re, $story->story_id) || preg_match($re, $story->title.'/'.$story->name)) {
                    $matches = true;
                    break;
                }
            }
            if (! $matches) {
                return false;
            }
        }
        $affected = $build->options['turboSnap']['affectedImportPaths'] ?? null;
        if (is_array($affected) && $story->import_path !== null) {
            return in_array(ltrim($story->import_path, './'), array_map(fn ($p) => ltrim((string) $p, './'), $affected), true);
        }

        return true;
    }

    public function fail(Build $build, string $message): void
    {
        $build->forceFill(['status' => BuildStatus::Failed, 'error' => $message, 'completed_at' => now()])->save();
        BuildUpdated::dispatch($build);
        app(BuildFinalizer::class)->afterStatusChange($build);
    }
}
