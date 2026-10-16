<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('snapshots', function (Blueprint $table) {
            $table->json('a11y')->nullable(); // axe violations
            $table->unsignedInteger('a11y_new_count')->default(0); // violations not present in the baseline
        });
        Schema::table('builds', function (Blueprint $table) {
            $table->unsignedInteger('a11y_count')->default(0);
            $table->unsignedInteger('a11y_new_count')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('snapshots', fn (Blueprint $t) => $t->dropColumn(['a11y', 'a11y_new_count']));
        Schema::table('builds', fn (Blueprint $t) => $t->dropColumn(['a11y_count', 'a11y_new_count']));
    }
};
