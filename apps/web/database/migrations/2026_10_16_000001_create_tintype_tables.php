<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        Schema::create('organization_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role')->default('reviewer'); // owner | admin | reviewer | viewer
            $table->timestamps();
            $table->unique(['organization_id', 'user_id']);
        });

        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('token_prefix', 16)->index();
            $table->string('token_hash', 64);
            $table->string('default_branch')->default('main');
            $table->string('github_repository')->nullable(); // owner/name
            $table->unsignedBigInteger('github_installation_id')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'slug']);
        });

        Schema::create('commits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('sha', 64);
            $table->json('parents');
            $table->string('message', 1000)->nullable();
            $table->string('author')->nullable();
            $table->timestamp('committed_at')->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'sha']);
        });

        Schema::create('builds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->string('commit_sha', 64)->index();
            $table->string('branch')->index();
            $table->unsignedInteger('pr_number')->nullable()->index();
            $table->string('status')->default('uploading')->index();
            $table->json('baseline_build_ids')->nullable();
            $table->string('baseline_strategy')->nullable(); // ancestors | default-branch | none
            $table->json('browsers');
            $table->string('capture_image')->nullable();
            $table->string('cli_version')->nullable();
            $table->json('ci')->nullable();
            $table->json('options')->nullable(); // autoAcceptChanges, onlyStoryNames, ...
            $table->unsignedInteger('story_count')->default(0);
            $table->unsignedInteger('snapshot_count')->default(0);
            $table->unsignedInteger('changed_count')->default(0);
            $table->unsignedInteger('new_count')->default(0);
            $table->unsignedInteger('unchanged_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedInteger('removed_count')->default(0);
            $table->unsignedInteger('inconsistent_count')->default(0);
            $table->unsignedBigInteger('github_check_run_id')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'number']);
        });

        Schema::create('stories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('story_id');
            $table->string('title');
            $table->string('name');
            $table->string('import_path')->nullable();
            $table->string('component_path')->nullable();
            $table->json('tags')->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'story_id']);
        });

        Schema::create('images', function (Blueprint $table) {
            $table->string('sha256', 64)->primary();
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->unsignedBigInteger('bytes');
            $table->timestamps();
        });

        Schema::create('snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('build_id')->constrained()->cascadeOnDelete();
            $table->foreignId('story_id')->constrained()->cascadeOnDelete();
            $table->string('browser', 16);
            $table->string('mode_key', 32);
            $table->string('mode_name')->default('default');
            $table->unsignedInteger('viewport_width')->nullable();
            $table->unsignedInteger('viewport_height')->nullable();
            // new | unchanged | changed | error | skipped | removed | inconsistent
            $table->string('status')->index();
            // null (nothing to review) | pending | accepted | denied
            $table->string('review_state')->nullable()->index();
            $table->string('image_sha', 64)->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->foreignId('baseline_snapshot_id')->nullable()->constrained('snapshots')->nullOnDelete();
            $table->string('diff_sha', 64)->nullable();
            $table->double('diff_ratio')->nullable();
            $table->json('diff_regions')->nullable();
            $table->json('ignore_regions')->nullable();
            $table->json('params')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->unique(['build_id', 'story_id', 'browser', 'mode_key']);
            $table->index(['story_id', 'browser', 'mode_key']);
        });

        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('snapshot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('decision'); // accepted | denied | reset
            $table->string('source')->default('user'); // user | auto | cli
            $table->timestamps();
        });

        Schema::create('capture_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('build_id')->constrained()->cascadeOnDelete();
            $table->string('browser', 16);
            $table->json('story_ids');
            $table->string('status')->default('queued')->index(); // queued | leased | completed | failed
            $table->string('worker_id')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->timestamps();
        });

        Schema::create('workers', function (Blueprint $table) {
            $table->id();
            $table->string('worker_id')->unique();
            $table->string('arch')->nullable();
            $table->string('image')->nullable();
            $table->json('browsers')->nullable();
            $table->string('browser_version')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

        Schema::create('app_settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        Schema::create('github_installations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('installation_id')->unique();
            $table->string('account_login');
            $table->string('account_type')->nullable();
            $table->timestamps();
        });

        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('build_id')->constrained()->cascadeOnDelete();
            $table->foreignId('snapshot_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('comments')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['comments', 'github_installations', 'app_settings', 'workers', 'capture_jobs', 'reviews', 'snapshots', 'images', 'stories', 'builds', 'commits', 'projects', 'organization_user', 'organizations'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
