<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\CommentOnBuild;
use App\Mcp\Tools\GetBuild;
use App\Mcp\Tools\GetPullRequest;
use App\Mcp\Tools\GetSnapshot;
use App\Mcp\Tools\ListBuilds;
use App\Mcp\Tools\ListProjects;
use App\Mcp\Tools\ListPullRequests;
use App\Mcp\Tools\ReviewPullRequest;
use App\Mcp\Tools\ReviewSnapshots;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Tintype')]
#[Version('1.0.0')]
#[Instructions(<<<'TXT'
Tintype is a visual regression service for Storybook. Each CI run creates a build that
captures every story in real browsers and compares each snapshot with its baseline (the
last accepted image on the branch's git ancestry).

Typical flow: list-projects to find a project ("org/project"), then list-builds or
list-pull-requests, get-build or get-pull-request for what changed, get-snapshot to look
at the new image, baseline and diff, and finally review-snapshots (accept/deny) or
review-pull-request (approve). Accepting a snapshot makes it the baseline for later
builds, so look at the images before accepting. You act as the token's user, with that
user's role.
TXT)]
class TintypeServer extends Server
{
    protected array $tools = [
        ListProjects::class,
        ListBuilds::class,
        GetBuild::class,
        GetSnapshot::class,
        ReviewSnapshots::class,
        CommentOnBuild::class,
        ListPullRequests::class,
        GetPullRequest::class,
        ReviewPullRequest::class,
    ];
}
