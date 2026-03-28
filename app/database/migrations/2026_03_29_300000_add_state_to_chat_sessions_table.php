<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add server-side conversation state columns to chat_sessions.
 *
 * Previously the slot-filling context (primary_filter, question) was
 * managed entirely client-side. These columns make the DB the source
 * of truth so the conversation state survives page reloads and can be
 * loaded from session history without replaying turns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_sessions', function (Blueprint $table) {
            // The entity being asked about (e.g. product name)
            $table->string('current_primary_filter')->nullable()->after('title');
            // The extracted support question
            $table->text('current_question')->nullable()->after('current_primary_filter');
            // Conversation flow state machine
            // idle | waiting_for_filter | searching | answered
            $table->string('state', 30)->default('idle')->after('current_question');
        });
    }

    public function down(): void
    {
        Schema::table('chat_sessions', function (Blueprint $table) {
            $table->dropColumn(['current_primary_filter', 'current_question', 'state']);
        });
    }
};
