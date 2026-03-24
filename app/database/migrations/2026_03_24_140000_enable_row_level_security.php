<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Enable PostgreSQL Row Level Security on all tenant-scoped tables.
 *
 * CTO directive: RLS as defense-in-depth alongside Eloquent global scopes.
 * Uses the session variable app.tenant_id set by middleware on each request.
 *
 * Tables without tenant_id (knowledge_unit_versions, knowledge_unit_reviews)
 * are protected indirectly via FK cascade from knowledge_units.
 */
return new class extends Migration
{
    /**
     * Tables that have a direct tenant_id column and need RLS policies.
     */
    private const TENANT_TABLES = [
        'datasets',
        'dataset_rows',
        'pipeline_jobs',
        'clusters',
        'knowledge_units',
        'llm_models',
        'exports',
    ];

    public function up(): void
    {
        foreach (self::TENANT_TABLES as $table) {
            // Enable RLS on the table
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");

            // Create a policy that restricts access to the current tenant
            // The app.tenant_id session variable is set by TenantScope middleware
            DB::statement("
                CREATE POLICY tenant_isolation_{$table} ON {$table}
                USING (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::bigint)
            ");
        }

        // Tables with indirect tenant isolation via FK to clusters
        foreach (['cluster_centroids', 'cluster_representatives', 'cluster_memberships'] as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("
                CREATE POLICY tenant_isolation_{$table} ON {$table}
                USING (cluster_id IN (
                    SELECT id FROM clusters
                    WHERE tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::bigint
                ))
            ");
        }
    }

    public function down(): void
    {
        foreach (self::TENANT_TABLES as $table) {
            DB::statement("DROP POLICY IF EXISTS tenant_isolation_{$table} ON {$table}");
            DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
        }

        foreach (['cluster_centroids', 'cluster_representatives', 'cluster_memberships'] as $table) {
            DB::statement("DROP POLICY IF EXISTS tenant_isolation_{$table} ON {$table}");
            DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
        }
    }
};
