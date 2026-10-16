<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pull_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->string('title')->nullable();
            $table->string('state')->default('open'); // open | closed | merged
            $table->string('head_branch')->nullable()->index();
            $table->string('head_sha', 64)->nullable();
            $table->string('base_branch')->nullable();
            $table->string('merge_commit_sha', 64)->nullable()->index();
            $table->string('author')->nullable();
            $table->string('url')->nullable();
            // UI Review
            $table->string('review_status')->default('open'); // open | approved | changes_requested
            $table->unsignedBigInteger('ui_review_check_run_id')->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'number']);
        });

        Schema::create('pull_request_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pull_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('state'); // approved | changes_requested | assigned | commented
            $table->text('body')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pull_request_reviews');
        Schema::dropIfExists('pull_requests');
    }
};
