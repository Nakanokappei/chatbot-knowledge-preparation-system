<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create the clusters table.
 *
 * Clusters are intermediate artifacts produced by the clustering step.
 * Each cluster groups related support log entries by topic similarity.
 * LLM-generated fields (topic_name, intent, summary) are populated
 * during the cluster_analysis step.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clusters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pipeline_job_id')->constrained('pipeline_jobs')->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->integer('cluster_label'); // HDBSCAN output label (-1 = noise)
            $table->string('topic_name')->nullable(); // LLM-generated topic name
            $table->string('intent')->nullable(); // LLM-generated user intent
            $table->text('summary')->nullable(); // LLM-generated cluster summary
            $table->unsignedInteger('row_count')->default(0);
            $table->decimal('quality_score', 5, 4)->nullable(); // silhouette score etc.
            $table->jsonb('representative_row_ids_json')->nullable();
            $table->timestamps();

            $table->index(['pipeline_job_id', 'tenant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clusters');
    }
};
