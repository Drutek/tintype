<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pull_requests', function (Blueprint $table) {
            // Hash of the UI changes the current review decision was made on; a push that
            // alters them dismisses the decision.
            $table->string('review_fingerprint', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('pull_requests', fn (Blueprint $t) => $t->dropColumn('review_fingerprint'));
    }
};
