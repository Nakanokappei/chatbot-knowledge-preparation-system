<?php

/**
 * Add chat feedback tracking:
 * 1. usage_count on knowledge_units — incremented each time a KU is used in a chat answer
 * 2. answer_feedback table — stores upvote/downvote per chat response
 * 3. chat answer counts on daily_cost_summary — daily chat_answers, upvotes, downvotes
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Track how many times each KU has been cited in chat answers
        Schema::table('knowledge_units', function (Blueprint $table) {
            $table->unsignedInteger('usage_count')->default(0)->after('review_status');
        });

        // Store per-answer feedback (upvote/downvote)
        Schema::create('answer_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->unsignedBigInteger('embedding_id');
            $table->string('vote', 10); // 'up' or 'down'
            $table->text('question')->nullable();
            $table->text('answer')->nullable();
            $table->json('source_ku_ids')->nullable(); // array of KU IDs used
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['embedding_id']);
        });

        // Add chat answer tracking columns to daily summary
        Schema::table('daily_cost_summary', function (Blueprint $table) {
            $table->unsignedInteger('chat_answers')->default(0)->after('request_count');
            $table->unsignedInteger('upvotes')->default(0)->after('chat_answers');
            $table->unsignedInteger('downvotes')->default(0)->after('upvotes');
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_units', function (Blueprint $table) {
            $table->dropColumn('usage_count');
        });
        Schema::dropIfExists('answer_feedback');
        Schema::table('daily_cost_summary', function (Blueprint $table) {
            $table->dropColumn(['chat_answers', 'upvotes', 'downvotes']);
        });
    }
};
