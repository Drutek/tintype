<?php

namespace App\Mcp\Tools;

use App\Models\PullRequest;
use App\Models\PullRequestReview;
use App\Services\PullRequestReviewer;
use App\Services\UiReview;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Show a pull request\'s UI Review: every visual difference between its latest build and the base branch, its review status and history, and how many snapshots still need accepting for the Visual Tests check.')]
#[IsReadOnly]
class GetPullRequest extends TintypeTool
{
    public function __construct(private readonly UiReview $ui) {}

    public function handle(Request $request): ResponseFactory
    {
        $request->validate(['number' => ['required', 'integer']]);
        [$project] = $this->project($request);
        $number = (int) $request->get('number');
        $pr = PullRequest::firstOrNew(['project_id' => $project->id, 'number' => $number]);
        $cmp = $this->ui->compare($project, $pr, $number);
        $this->ui->dismissStaleReview($pr, $cmp);

        return Response::structured([
            'number' => $number,
            'title' => $pr->title,
            'state' => $pr->state ?? 'open',
            'reviewStatus' => $pr->review_status ?? 'open',
            'headBranch' => $pr->head_branch ?? $cmp['head']?->branch,
            'baseBranch' => $pr->base_branch ?? $project->default_branch,
            'headBuild' => $cmp['head'] ? ['number' => $cmp['head']->number, 'status' => $cmp['head']->status->value] : null,
            'baseBuild' => $cmp['base'] ? ['number' => $cmp['base']->number, 'branch' => $cmp['base']->branch] : null,
            'pendingInHead' => $cmp['head'] ? PullRequestReviewer::pendingIn($cmp['head'])->count() : 0,
            'changes' => collect($cmp['changes'])->map(fn ($c) => [
                'story' => "{$c['story']->title} / {$c['story']->name}",
                'browser' => $c['browser'],
                'mode' => $c['mode'],
                'kind' => $c['kind'],
                'snapshot' => ($c['after'] ?? $c['before'])?->id,
                'review' => $c['after']?->review_state?->value,
            ])->values()->all(),
            'reviews' => $pr->exists ? $pr->reviews()->with('user:id,name')->latest('id')->limit(20)->get()
                ->map(fn (PullRequestReview $r) => ['state' => $r->state, 'user' => $r->user?->name, 'body' => $r->body, 'at' => $r->created_at->toIso8601String()])->all() : [],
            'url' => route('pulls.show', [$project->organization, $project, $number]),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $this->projectSchema($schema),
            'number' => $schema->integer()->description('Pull request number.')->required(),
        ];
    }
}
