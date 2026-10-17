<?php

namespace App\Mcp\Tools;

use App\Models\Snapshot;
use App\Services\ImageStore;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Show one snapshot with its images: the new capture, the baseline it was compared with, and the diff (changed pixels highlighted).')]
#[IsReadOnly]
class GetSnapshot extends TintypeTool
{
    public function __construct(private readonly ImageStore $images) {}

    public function handle(Request $request): ResponseFactory
    {
        $request->validate(['snapshot' => ['required', 'integer'], 'images' => ['nullable', 'array'], 'images.*' => ['in:new,baseline,diff']]);
        [$project] = $this->project($request);
        $snap = Snapshot::with(['story', 'baseline', 'build'])->find((int) $request->get('snapshot'));
        if (! $snap || $snap->build->project_id !== $project->id) {
            throw ValidationException::withMessages(['snapshot' => 'Snapshot not found in this project.']);
        }
        $snap->build->setRelation('project', $project);
        $info = $this->snapshotSummary($snap) + [
            'build' => $snap->build->number,
            'viewport' => $snap->viewport_width ? "{$snap->viewport_width}×{$snap->viewport_height}" : null,
            'size' => $snap->width ? "{$snap->width}×{$snap->height}" : null,
            'baselineFromBuild' => $snap->baseline?->build_id ? $snap->baseline->build()->value('number') : null,
            'url' => $snap->build->webUrl().'?snapshot='.$snap->id,
        ];

        $responses = [Response::text(json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))];
        $which = $request->get('images') ?: ['new', 'baseline', 'diff'];
        foreach (['new' => $snap->image_sha, 'baseline' => $snap->baseline?->image_sha, 'diff' => $snap->diff_sha] as $kind => $sha) {
            if (! in_array($kind, $which, true) || ! $sha || ! ($bytes = $this->images->get($sha))) {
                continue;
            }
            $responses[] = Response::text("{$kind}:");
            $responses[] = Response::image($bytes, 'image/png');
        }

        return Response::make($responses)->withStructuredContent($info);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $this->projectSchema($schema),
            'snapshot' => $schema->integer()->description('Snapshot id (from get-build or get-pull-request).')->required(),
            'images' => $schema->array()->items($schema->string()->enum(['new', 'baseline', 'diff']))->description('Which images to return (default: all three).'),
        ];
    }
}
