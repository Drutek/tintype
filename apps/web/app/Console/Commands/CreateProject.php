<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\Project;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CreateProject extends Command
{
    protected $signature = 'tintype:project {org : organization slug or name} {name} {--repo= : GitHub owner/name} {--branch=main} {--json}';

    protected $description = 'Create a project and print its token';

    public function handle(): int
    {
        $org = Organization::firstOrCreate(['slug' => Str::slug($this->argument('org'))], ['name' => $this->argument('org')]);
        $project = Project::firstOrNew(['organization_id' => $org->id, 'slug' => Str::slug($this->argument('name'))]);
        $project->fill(['name' => $this->argument('name'), 'default_branch' => $this->option('branch'), 'github_repository' => $this->option('repo')]);
        $project->token_prefix ??= '';
        $project->token_hash ??= '';
        $token = $project->rotateToken();
        if ($this->option('json')) {
            $this->line(json_encode(['organization' => $org->slug, 'project' => $project->slug, 'token' => $token]));
        } else {
            $this->info("Project {$org->slug}/{$project->slug} token: {$token}");
        }

        return self::SUCCESS;
    }
}
