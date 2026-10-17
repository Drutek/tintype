<?php

namespace App\Mcp\Tools;

use App\Models\Build;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('List a project\'s recent builds, newest first. Filter by branch or status (e.g. "pending" for builds awaiting review).')]
#[IsReadOnly]
class ListBuilds extends TintypeTool
{
    public function handle(Request $request): ResponseFactory
    {
        $request->validate(['branch' => ['nullable', 'string'], 'status' => ['nullable', 'string'], 'limit' => ['nullable', 'integer', 'min:1', 'max:50']]);
        [$project] = $this->project($request);
        $builds = $project->builds()
            ->when($request->get('branch'), fn ($q, $b) => $q->where('branch', $b))
            ->when($request->get('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('number')->limit((int) ($request->get('limit') ?? 10))->get()
            ->map(fn (Build $b) => $this->buildSummary($b->setRelation('project', $project)));

        return Response::structured(['builds' => $builds->values()->all()]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $this->projectSchema($schema),
            'branch' => $schema->string()->description('Only builds on this branch.'),
            'status' => $schema->string()->enum(['capturing', 'pending', 'accepted', 'denied', 'passed', 'broken', 'failed', 'canceled', 'superseded'])->description('Only builds with this status.'),
            'limit' => $schema->integer()->min(1)->max(50)->description('How many builds (default 10).'),
        ];
    }
}
