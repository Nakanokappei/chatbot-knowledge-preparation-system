<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add source_type column and make cluster_id / pipeline_job_id nullable
 * to support manual QA registration (Feature 3).
 *
 * source_type values: 'pipeline' (default) | 'manual' | 'faq_cluster'
 */
return new class extends Migration
{
    public function up(): void
    {
        // Add source_type column
        Schema::table('knowledge_units', function (Blueprint $table) {
            $table->string('source_type', 20)->default('pipeline')->after('version');
            $table->index(['workspace_id', 'source_type']);
        });

        // Make cluster_id and pipeline_job_id nullable for manual KUs.
        // The original migration uses constrained() with cascadeOnDelete;
        // we need to drop those constraints and recreate as nullable.
        DB::statement('ALTER TABLE knowledge_units ALTER COLUMN cluster_id DROP NOT NULL');
        DB::statement('ALTER TABLE knowledge_units ALTER COLUMN pipeline_job_id DROP NOT NULL');

        // Update FK actions: cascadeOnDelete -> SET NULL on delete
        DB::statement('ALTER TABLE knowledge_units DROP CONSTRAINT IF EXISTS knowledge_units_cluster_id_foreign');
        DB::statement('ALTER TABLE knowledge_units ADD CONSTRAINT knowledge_units_cluster_id_foreign FOREIGN KEY (cluster_id) REFERENCES clusters(id) ON DELETE SET NULL');

        DB::statement('ALTER TABLE knowledge_units DROP CONSTRAINT IF EXISTS knowledge_units_pipeline_job_id_foreign');
        DB::statement('ALTER TABLE knowledge_units ADD CONSTRAINT knowledge_units_pipeline_job_id_foreign FOREIGN KEY (pipeline_job_id) REFERENCES pipeline_jobs(id) ON DELETE SET NULL');
    }

    public function down(): void
    {
        // Remove manual KUs (cluster_id/pipeline_job_id are null) before restoring NOT NULL
        DB::statement("DELETE FROM knowledge_units WHERE source_type IN ('manual', 'faq_cluster')");

        DB::statement('ALTER TABLE knowledge_units ALTER COLUMN cluster_id SET NOT NULL');
        DB::statement('ALTER TABLE knowledge_units ALTER COLUMN pipeline_job_id SET NOT NULL');

        // Restore cascadeOnDelete
        DB::statement('ALTER TABLE knowledge_units DROP CONSTRAINT IF EXISTS knowledge_units_cluster_id_foreign');
        DB::statement('ALTER TABLE knowledge_units ADD CONSTRAINT knowledge_units_cluster_id_foreign FOREIGN KEY (cluster_id) REFERENCES clusters(id) ON DELETE CASCADE');

        DB::statement('ALTER TABLE knowledge_units DROP CONSTRAINT IF EXISTS knowledge_units_pipeline_job_id_foreign');
        DB::statement('ALTER TABLE knowledge_units ADD CONSTRAINT knowledge_units_pipeline_job_id_foreign FOREIGN KEY (pipeline_job_id) REFERENCES pipeline_jobs(id) ON DELETE CASCADE');

        Schema::table('knowledge_units', function (Blueprint $table) {
            $table->dropIndex(['workspace_id', 'source_type']);
            $table->dropColumn('source_type');
        });
    }
};
