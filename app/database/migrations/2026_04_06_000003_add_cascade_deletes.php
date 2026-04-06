<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add ON DELETE CASCADE to foreign keys that currently use RESTRICT or SET NULL.
 *
 * This ensures that deleting a dataset or embedding automatically removes all
 * dependent rows (pipeline jobs, clusters, knowledge units, etc.) without
 * requiring manual deletion in controller code.
 *
 * Cascade chain:
 *   dataset → dataset_rows (already CASCADE)
 *   dataset → embeddings → pipeline_jobs → clusters → (memberships, centroids, representatives, analysis_logs)
 *   dataset → embeddings → knowledge_units → (versions, reviews)
 *   dataset → pipeline_jobs → (clusters, exports)
 *   dataset → knowledge_units
 */
return new class extends Migration
{
    /**
     * FK constraints to update: [table, constraint_name, column, references_table].
     * Each will be dropped and recreated with ON DELETE CASCADE.
     */
    private array $constraints = [
        // dataset → embeddings
        ['embeddings',      'embeddings_dataset_id_foreign',      'dataset_id',      'datasets'],
        // dataset → pipeline_jobs
        ['pipeline_jobs',   'pipeline_jobs_dataset_id_foreign',   'dataset_id',      'datasets'],
        // dataset → knowledge_units
        ['knowledge_units', 'knowledge_units_dataset_id_foreign', 'dataset_id',      'datasets'],
        // embedding → pipeline_jobs (was SET NULL)
        ['pipeline_jobs',   'pipeline_jobs_embedding_id_foreign', 'embedding_id',    'embeddings'],
        // embedding → knowledge_units (was SET NULL)
        ['knowledge_units', 'knowledge_units_embedding_id_foreign', 'embedding_id',  'embeddings'],
        // embedding → clusters (was SET NULL)
        ['clusters',        'clusters_embedding_id_foreign',      'embedding_id',    'embeddings'],
    ];

    public function up(): void
    {
        foreach ($this->constraints as [$table, $constraint, $column, $refTable]) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$constraint}");
            DB::statement(
                "ALTER TABLE {$table} ADD CONSTRAINT {$constraint} "
                . "FOREIGN KEY ({$column}) REFERENCES {$refTable}(id) ON DELETE CASCADE"
            );
        }
    }

    public function down(): void
    {
        // Restore original behavior: RESTRICT for non-nullable, SET NULL for nullable
        $nullable = ['pipeline_jobs_embedding_id_foreign', 'knowledge_units_embedding_id_foreign', 'clusters_embedding_id_foreign'];

        foreach ($this->constraints as [$table, $constraint, $column, $refTable]) {
            $action = in_array($constraint, $nullable) ? 'SET NULL' : 'RESTRICT';
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$constraint}");
            DB::statement(
                "ALTER TABLE {$table} ADD CONSTRAINT {$constraint} "
                . "FOREIGN KEY ({$column}) REFERENCES {$refTable}(id) ON DELETE {$action}"
            );
        }
    }
};
