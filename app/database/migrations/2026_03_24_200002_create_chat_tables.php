<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chat tables — minimal conversation tracking for RAG validation.
 *
 * Phase 4 scope is a RAG verification API, not a full conversational UI.
 * Keep these tables lean per CTO directive.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Conversation container — one per chat session
        Schema::create('chat_conversations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->foreignId('knowledge_dataset_id')->constrained('knowledge_datasets');
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['tenant_id', 'knowledge_dataset_id']);
        });

        // Individual messages within a conversation
        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->uuid('conversation_id');
            $table->foreign('conversation_id')
                  ->references('id')
                  ->on('chat_conversations')
                  ->cascadeOnDelete();
            $table->string('role', 20); // user, assistant
            $table->text('content');
            $table->jsonb('sources_json')->nullable(); // [{ku_id, topic, similarity}]
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('chat_conversations');
    }
};
