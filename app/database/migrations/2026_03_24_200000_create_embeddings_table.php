<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create the embeddings table — the central entity linking datasets to
 * knowledge units. An embedding represents a specific column configuration
 * applied to a dataset, producing a vector space from which clusters and
 * KUs are derived.
 *
 * Also adds embedding_id to knowledge_units and clusters so they belong
 * to an embedding rather than directly to a pipeline_job.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Create embeddings table
        Schema::create('embeddings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->foreignId('dataset_id')->constrained('datasets');
            $table->string('name');
            $table->jsonb('column_config_json')->nullable();
            $table->string('embedding_model')->default('amazon.titan-embed-text-v2:0');
            $table->string('status')->default('draft'); // draft, processing, ready, failed
            $table->integer('row_count')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
        });

        // Add embedding_id to clusters
        Schema::table('clusters', function (Blueprint $table) {
            $table->foreignId('embedding_id')->nullable()->after('pipeline_job_id')
                ->constrained('embeddings')->nullOnDelete();
        });

        // Add embedding_id to knowledge_units
        Schema::table('knowledge_units', function (Blueprint $table) {
            $table->foreignId('embedding_id')->nullable()->after('pipeline_job_id')
                ->constrained('embeddings')->nullOnDelete();
        });

        // Add embedding_id to pipeline_jobs (a job belongs to an embedding)
        Schema::table('pipeline_jobs', function (Blueprint $table) {
            $table->foreignId('embedding_id')->nullable()->after('dataset_id')
                ->constrained('embeddings')->nullOnDelete();
        });

        // Enable RLS on embeddings
        DB::statement('ALTER TABLE embeddings ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE embeddings FORCE ROW LEVEL SECURITY');
        DB::statement("
            CREATE POLICY tenant_isolation_embeddings ON embeddings
            USING (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::bigint)
        ");
    }

    public function down(): void
    {
        Schema::table('pipeline_jobs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('embedding_id');
        });
        Schema::table('knowledge_units', function (Blueprint $table) {
            $table->dropConstrainedForeignId('embedding_id');
        });
        Schema::table('clusters', function (Blueprint $table) {
            $table->dropConstrainedForeignId('embedding_id');
        });

        DB::statement('DROP POLICY IF EXISTS tenant_isolation_embeddings ON embeddings');
        Schema::dropIfExists('embeddings');
    }
};
