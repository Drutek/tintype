<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pull_requests', function (Blueprint $table) {
            $table->json('assignees')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('pull_requests', fn (Blueprint $t) => $t->dropColumn('assignees'));
    }
};
