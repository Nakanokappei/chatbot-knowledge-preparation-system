<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Create chat_sessions and chat_turns tables for workspace embedding chat history.
 *
 * chat_sessions: one row per conversation thread (user + embedding).
 * chat_turns: individual user/assistant turns within a session.
 *
 * Note: the existing chat_messages table belongs to the KnowledgeDataset RAG API
 * (ChatConversation model). These new tables serve the workspace embedding overlay.
 *
 * RLS is applied so workspaces cannot see each other's history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('embedding_id')->constrained()->cascadeOnDelete();
            // Short summary derived from the first user message (for sidebar display)
            $table->string('title', 120)->nullable();
            $table->timestamps();

            $table->index(['embedding_id', 'created_at']);
            $table->index(['workspace_id', 'user_id', 'created_at']);
        });

        Schema::create('chat_turns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')
                ->references('id')->on('chat_sessions')
                ->cascadeOnDelete();
            $table->enum('role', ['user', 'assistant']);
            $table->text('content');
            // Store sources and context as JSON for history replay
            $table->jsonb('sources')->nullable();
            $table->jsonb('context')->nullable();
            $table->string('action', 30)->nullable(); // answer / no_match / rejected / …
            $table->timestamps();

            $table->index(['session_id', 'created_at']);
        });

        // Enable RLS on both tables
        DB::statement('ALTER TABLE chat_sessions ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE chat_turns ENABLE ROW LEVEL SECURITY');

        // chat_sessions: workspace isolation
        DB::statement("
            CREATE POLICY workspace_isolation_chat_sessions ON chat_sessions
            USING (
                workspace_id = NULLIF(current_setting('app.workspace_id', true), '')::bigint
                OR current_setting('app.is_system_admin', true) = 'true'
            )
        ");

        // chat_turns: isolated via session (JOIN to chat_sessions)
        DB::statement("
            CREATE POLICY workspace_isolation_chat_turns ON chat_turns
            USING (
                session_id IN (
                    SELECT id FROM chat_sessions
                    WHERE workspace_id = NULLIF(current_setting('app.workspace_id', true), '')::bigint
                )
                OR current_setting('app.is_system_admin', true) = 'true'
            )
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_turns');
        Schema::dropIfExists('chat_sessions');
    }
};
