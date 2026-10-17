<?php

namespace App\Mcp\Tools;

use App\Models\PullRequest;
use App\Services\PullRequestReviewer;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('List a project\'s pull requests with their UI Review status and the latest build.')]
#[IsReadOnly]
class ListPullRequests extends TintypeTool
{
    public function handle(Request $request): ResponseFactory
    {
        $request->validate(['state' => ['nullable', 'in:open,closed,merged,all']]);
        [$project] = $this->project($request);
        $state = $request->get('state') ?? 'open';
        $known = PullRequest::where('project_id', $project->id)->get()->keyBy('number');
        $numbers = $project->builds()->whereNotNull('pr_number')->distinct()->pluck('pr_number')->merge($known->keys())->unique()->sortDesc();
        $rows = [];
        foreach ($numbers as $n) {
            $pr = $known->get($n);
            if ($state !== 'all' && ($pr->state ?? 'open') !== $state) {
                continue;
            }
            $latest = $project->builds()->where('pr_number', $n)->orderByDesc('number')->first();
            $rows[] = [
                'number' => (int) $n,
                'title' => $pr?->title,
                'state' => $pr->state ?? 'open',
                'reviewStatus' => $pr->review_status ?? 'open',
                'headBranch' => $pr->head_branch ?? $latest?->branch,
                'author' => $pr?->author,
                'latestBuild' => $latest ? ['number' => $latest->number, 'status' => $latest->status->value, 'pending' => PullRequestReviewer::pendingIn($latest)->count()] : null,
            ];
        }

        return Response::structured(['pullRequests' => $rows]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $this->projectSchema($schema),
            'state' => $schema->string()->enum(['open', 'closed', 'merged', 'all'])->description('Default: open.'),
        ];
    }
}
