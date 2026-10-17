<?php

namespace App\Mcp\Tools;

use App\Enums\ReviewState;
use App\Models\AuditLog;
use App\Services\ReviewService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Description('Accept or deny snapshots in a build (or reset them to pending). Accepted snapshots become the baseline for later builds. Pass snapshot ids, a component title, or all=true for every pending snapshot. Requires the reviewer role.')]
#[IsIdempotent]
class ReviewSnapshots extends TintypeTool
{
    public function __construct(private readonly ReviewService $reviews) {}

    public function handle(Request $request): ResponseFactory
    {
        $request->validate([
            'build' => ['required', 'integer'],
            'decision' => ['required', 'in:accepted,denied,pending'],
            'snapshots' => ['nullable', 'array'],
            'snapshots.*' => ['integer'],
            'component' => ['nullable', 'string'],
            'all' => ['nullable', 'boolean'],
        ]);
        [$project, $role] = $this->project($request);
        $this->requireReviewer($role);
        $build = $this->build($project, $request->get('build'));
        $decision = (string) $request->get('decision');
        $ids = $request->get('snapshots') ?? [];
        $component = $request->get('component');
        $all = (bool) $request->get('all');
        if (! $ids && ! $component && ! $all) {
            throw ValidationException::withMessages(['snapshots' => 'Pass snapshot ids, a component, or all=true.']);
        }
        $query = $build->snapshots()->whereNotNull('review_state')
            ->when($ids, fn ($q) => $q->whereIn('id', $ids))
            ->when($component, fn ($q) => $q->whereHas('story', fn ($s) => $s->where('title', $component)));
        if (! $ids && $decision !== 'pending') {
            $query->where('review_state', ReviewState::Pending->value);
        }
        try {
            $count = $this->reviews->decide($build, $query->get(), $decision, $this->user($request), 'mcp');
        } catch (InvalidArgumentException $e) {
            $hint = $build->status->value === 'superseded' ? ' It was superseded by build '.($build->options['supersededBy'] ?? '?').'; review that one.' : '';

            return Response::make(Response::error($e->getMessage().'.'.$hint));
        }
        if ($count && ! $ids) {
            AuditLog::record("build.bulk_{$decision}", $project->organization, $build, ['count' => $count, 'scope' => $component ? 'component' : 'all', 'source' => 'mcp']);
        }
        $build->refresh();

        return Response::structured(['updated' => $count, 'build' => $this->buildSummary($build)]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $this->projectSchema($schema),
            'build' => $schema->integer()->description('Build number.')->required(),
            'decision' => $schema->string()->enum(['accepted', 'denied', 'pending'])->description('"pending" undoes an earlier decision.')->required(),
            'snapshots' => $schema->array()->items($schema->integer())->description('Snapshot ids to decide.'),
            'component' => $schema->string()->description('Every pending snapshot of this component title (e.g. "Components/Hero").'),
            'all' => $schema->boolean()->description('Every pending snapshot in the build.'),
        ];
    }
}
