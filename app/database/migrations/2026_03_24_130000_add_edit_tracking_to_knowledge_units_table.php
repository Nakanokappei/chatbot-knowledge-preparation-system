<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add edit tracking columns to knowledge_units.
 *
 * CTO directive: Knowledge editing history is important for the future.
 * Track who edited, when, and with what comment for each edit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_units', function (Blueprint $table) {
            $table->foreignId('edited_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('edited_at')->nullable();
            $table->string('edit_comment', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_units', function (Blueprint $table) {
            $table->dropConstrainedForeignId('edited_by_user_id');
            $table->dropColumn(['edited_at', 'edit_comment']);
        });
    }
};
