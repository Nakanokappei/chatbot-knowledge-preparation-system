<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add retrieval metadata columns to chat_turns.
 *
 * search_mode tracks which retrieval strategy returned the answer
 * (precise, broad, relaxed, broad_unfiltered, none).
 * extracted_slots captures the LLM-extracted slot values at that turn
 * for debugging and analytics.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_turns', function (Blueprint $table) {
            // Which retrieval strategy produced the result
            $table->string('search_mode', 20)->nullable()->after('action');
            // Snapshot of LLM-extracted slot values at this turn
            $table->jsonb('extracted_slots')->nullable()->after('search_mode');
        });
    }

    public function down(): void
    {
        Schema::table('chat_turns', function (Blueprint $table) {
            $table->dropColumn(['search_mode', 'extracted_slots']);
        });
    }
};
