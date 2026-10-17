<?php

namespace App\Mcp\Tools;

use App\Enums\SnapshotStatus;
use App\Models\Snapshot;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Show a build: status, counts and the snapshots that need attention (changed, new, errors, inconsistent, removed). Use get-snapshot to see a snapshot\'s images.')]
#[IsReadOnly]
class GetBuild extends TintypeTool
{
    public function handle(Request $request): ResponseFactory
    {
        $request->validate(['build' => ['nullable', 'integer'], 'branch' => ['nullable', 'string'], 'include_unchanged' => ['nullable', 'boolean']]);
        [$project] = $this->project($request);
        $build = $this->build($project, $request->get('build'), $request->get('branch'));
        $snapshots = $build->snapshots()->with('story')
            ->when(! $request->get('include_unchanged'), fn ($q) => $q->whereNotIn('status', [SnapshotStatus::Unchanged->value, SnapshotStatus::Skipped->value]))
            ->get()
            ->sortBy(fn (Snapshot $s) => [$s->status->value, $s->story->title, $s->story->name])
            ->map(fn (Snapshot $s) => $this->snapshotSummary($s))->values()->all();

        return Response::structured($this->buildSummary($build) + [
            'baselineStrategy' => $build->baseline_strategy,
            'error' => $build->error,
            'snapshots' => $snapshots,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $this->projectSchema($schema),
            'build' => $schema->integer()->description('Build number; defaults to the latest build.'),
            'branch' => $schema->string()->description('With no build number: the latest build on this branch.'),
            'include_unchanged' => $schema->boolean()->description('Also list unchanged and skipped snapshots.'),
        ];
    }
}
