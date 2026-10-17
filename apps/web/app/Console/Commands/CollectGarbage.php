<?php

namespace App\Console\Commands;

use App\Models\Build;
use App\Models\Image;
use App\Models\Project;
use App\Models\Snapshot;
use App\Services\ImageStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CollectGarbage extends Command
{
    protected $signature = 'tintype:gc {--dry-run}';

    protected $description = 'Delete builds past retention (keeping baselines) and unreferenced images/Storybooks';

    public function handle(ImageStore $store): int
    {
        $dry = (bool) $this->option('dry-run');
        $deletedBuilds = 0;
        foreach (Project::all() as $project) {
            $cutoff = now()->subDays((int) $project->setting('retention_days'));
            // Builds that are the latest on their branch keep the lineage alive; so do builds
            // whose snapshots are referenced as baselines by newer builds.
            $keep = Build::where('project_id', $project->id)->selectRaw('max(id) as id')->groupBy('branch')->pluck('id');
            $old = Build::where('project_id', $project->id)->where('created_at', '<', $cutoff)->whereNotIn('id', $keep)->get();
            foreach ($old as $build) {
                $referenced = Snapshot::whereIn('baseline_snapshot_id', Snapshot::where('build_id', $build->id)->select('id'))
                    ->where('build_id', '!=', $build->id)->exists();
                if ($referenced) {
                    continue;
                }
                $deletedBuilds++;
                if (! $dry) {
                    $store->disk()->deleteDirectory($build->storybookPath());
                    $store->disk()->delete($build->storybookZipPath());
                    $build->delete();
                }
            }
        }

        $used = fn () => DB::table('snapshots')->whereNotNull('image_sha')->select('image_sha as sha')
            ->union(DB::table('snapshots')->whereNotNull('diff_sha')->select('diff_sha as sha'));
        $orphans = Image::whereNotIn('sha256', $used())->where('created_at', '<', now()->subHour())->pluck('sha256');
        if (! $dry) {
            foreach ($orphans->chunk(500) as $chunk) {
                foreach ($chunk as $sha) {
                    $store->disk()->delete(Image::pathFor($sha));
                }
                Image::whereIn('sha256', $chunk)->delete();
            }
        }
        $this->info(($dry ? '[dry run] ' : '')."Builds removed: {$deletedBuilds}; images removed: {$orphans->count()}");

        return self::SUCCESS;
    }
}
