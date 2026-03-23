<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create cluster_analysis_logs table.
 *
 * CTO directive: "LLM を使うシステムでは Prompt / Response Log は必須"
 * Stores every LLM invocation for reproducibility, quality analysis,
 * cost tracking, and audit purposes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cluster_analysis_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cluster_id')->constrained('clusters')->cascadeOnDelete();
            $table->foreignId('pipeline_job_id')->constrained('pipeline_jobs')->cascadeOnDelete();
            $table->text('prompt');
            $table->jsonb('response_json');
            $table->string('model');
            $table->string('prompt_version');
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->timestamps();

            $table->index('pipeline_job_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cluster_analysis_logs');
    }
};
