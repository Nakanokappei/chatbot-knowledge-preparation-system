<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Create the knowledge_units table.
 *
 * Knowledge Units are the FINAL PRODUCT of this system (Design Principle 7).
 * Each KU is generated from a cluster and represents a structured piece
 * of knowledge ready for chatbot consumption.
 *
 * The embedding column uses pgvector for similarity search and deduplication.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->foreignId('dataset_id')->constrained('datasets');
            $table->foreignId('pipeline_job_id')->constrained('pipeline_jobs')->cascadeOnDelete();
            $table->foreignId('cluster_id')->constrained('clusters')->cascadeOnDelete();
            $table->string('topic');
            $table->string('intent');
            $table->text('summary');
            $table->jsonb('typical_cases_json')->nullable(); // array of typical case strings
            $table->text('cause_summary');
            $table->text('resolution_summary');
            $table->text('notes')->nullable();
            $table->jsonb('representative_rows_json')->nullable(); // [{row_id, text}, ...]
            $table->jsonb('keywords_json')->nullable(); // array of keyword strings
            $table->unsignedInteger('row_count')->default(0);
            $table->decimal('confidence', 3, 2)->default(0.00); // 0.00 - 1.00
            $table->string('review_status')->default('draft'); // draft, reviewed, approved, published
            $table->jsonb('source_refs_json')->nullable(); // cluster_label, representative_row_ids
            $table->string('pipeline_config_version')->nullable();
            $table->string('prompt_version')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->index(['tenant_id', 'pipeline_job_id']);
            $table->index(['tenant_id', 'review_status']);
            $table->index(['cluster_id']);
        });

        // Add pgvector embedding column (cannot be done via Blueprint)
        DB::statement('ALTER TABLE knowledge_units ADD COLUMN embedding vector(1024)');
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_units');
    }
};
