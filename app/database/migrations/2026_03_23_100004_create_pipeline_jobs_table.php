<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create the pipeline_jobs table.
 *
 * Tracks the lifecycle of each pipeline execution.
 * Named pipeline_jobs to avoid collision with Laravel's built-in jobs table.
 * Status follows the Job State Machine defined in architecture docs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pipeline_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->foreignId('dataset_id')->constrained('datasets');
            $table->foreignId('pipeline_config_id')->nullable()->constrained('pipeline_configs');
            $table->string('status')->default('submitted');
            // Status values: submitted, validating, preprocessing, embedding,
            //   clustering, cluster_analysis, knowledge_unit_generation,
            //   exporting, completed, failed, cancelled
            $table->unsignedTinyInteger('progress')->default(0); // 0-100
            $table->jsonb('pipeline_config_snapshot_json')->nullable(); // immutable snapshot for reproducibility
            $table->jsonb('step_outputs_json')->nullable(); // per-step metadata (s3 paths, counts, metrics)
            $table->text('error_detail')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'created_at']);
            $table->index(['dataset_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pipeline_jobs');
    }
};
