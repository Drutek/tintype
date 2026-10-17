<?php

namespace App\Mcp\Tools;

use App\Services\PullRequestReviewer;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Approve a pull request\'s UI changes, request changes, or comment. This drives the UI Review check. With accept_pending=true an approval also accepts the head build\'s pending snapshots, clearing the Visual Tests check too. Requires the reviewer role.')]
class ReviewPullRequest extends TintypeTool
{
    public function __construct(private readonly PullRequestReviewer $reviewer) {}

    public function handle(Request $request): ResponseFactory
    {
        $request->validate([
            'number' => ['required', 'integer'],
            'state' => ['required', 'in:approved,changes_requested,commented'],
            'body' => ['nullable', 'string', 'max:5000'],
            'accept_pending' => ['nullable', 'boolean'],
        ]);
        [$project, $role] = $this->project($request);
        $this->requireReviewer($role);
        $number = (int) $request->get('number');
        $state = match ($request->get('state')) {
            'approved' => 'approved',
            'changes_requested' => 'changes_requested',
            default => 'commented',
        };
        try {
            $accepted = $this->reviewer->review($project, $number, $this->user($request), $state, $request->get('body'), (bool) $request->get('accept_pending'), 'mcp');
        } catch (InvalidArgumentException $e) {
            return Response::make(Response::error($e->getMessage()));
        }

        return Response::structured([
            'number' => $number,
            'state' => $request->get('state'),
            'acceptedSnapshots' => $accepted,
            'url' => route('pulls.show', [$project->organization, $project, $number]),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $this->projectSchema($schema),
            'number' => $schema->integer()->description('Pull request number.')->required(),
            'state' => $schema->string()->enum(['approved', 'changes_requested', 'commented'])->required(),
            'body' => $schema->string()->description('Optional review comment.'),
            'accept_pending' => $schema->boolean()->description('With approved: also accept the head build\'s pending snapshots.'),
        ];
    }
}
