<?php

namespace App\Mcp\Tools;

use App\Models\Comment;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Comment on a build, or on one snapshot in it. Requires the reviewer role.')]
class CommentOnBuild extends TintypeTool
{
    public function handle(Request $request): ResponseFactory
    {
        $request->validate(['build' => ['required', 'integer'], 'body' => ['required', 'string', 'max:5000'], 'snapshot' => ['nullable', 'integer']]);
        [$project, $role] = $this->project($request);
        $this->requireReviewer($role);
        $build = $this->build($project, $request->get('build'));
        $snapshot = $request->get('snapshot');
        if ($snapshot && ! $build->snapshots()->whereKey($snapshot)->exists()) {
            throw ValidationException::withMessages(['snapshot' => 'Snapshot not found in this build.']);
        }
        $comment = Comment::create(['build_id' => $build->id, 'snapshot_id' => $snapshot, 'user_id' => $this->user($request)->id, 'body' => (string) $request->get('body')]);

        return Response::structured(['comment' => $comment->id, 'url' => $build->webUrl().($snapshot ? "?snapshot={$snapshot}" : '')]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $this->projectSchema($schema),
            'build' => $schema->integer()->description('Build number.')->required(),
            'body' => $schema->string()->description('Comment text.')->required(),
            'snapshot' => $schema->integer()->description('Attach the comment to this snapshot.'),
        ];
    }
}
