<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cost tracking tables for token usage and daily cost summaries.
 *
 * token_usage: per-request granular tracking
 * daily_cost_summary: aggregated for dashboard speed and billing
 *
 * CTO directive: cost tracking is essential for SaaS operation.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Per-request token usage log
        Schema::create('token_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->string('endpoint', 50);  // retrieve, chat, cluster_analysis, embedding
            $table->string('model_id');
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->decimal('estimated_cost', 10, 6)->default(0);  // USD
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'endpoint']);
        });

        // Daily aggregated cost summary (CTO directive: fast dashboard + billing)
        Schema::create('daily_cost_summary', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->date('date');
            $table->decimal('embedding_cost', 10, 6)->default(0);
            $table->decimal('chat_cost', 10, 6)->default(0);
            $table->decimal('pipeline_cost', 10, 6)->default(0);
            $table->decimal('total_cost', 10, 6)->default(0);
            $table->unsignedInteger('total_tokens')->default(0);
            $table->unsignedInteger('request_count')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_cost_summary');
        Schema::dropIfExists('token_usage');
    }
};
