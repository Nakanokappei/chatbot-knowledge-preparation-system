<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add response_ms to chat_turns to record LLM response latency.
 *
 * Stored only on assistant turns. Enables per-hour latency charting
 * in the system health dashboard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_turns', function (Blueprint $table) {
            // LLM invocation latency in milliseconds, nullable (only set on assistant turns)
            $table->unsignedInteger('response_ms')->nullable()->after('search_mode');
        });
    }

    public function down(): void
    {
        Schema::table('chat_turns', function (Blueprint $table) {
            $table->dropColumn('response_ms');
        });
    }
};
