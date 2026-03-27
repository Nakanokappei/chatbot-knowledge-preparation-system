<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rename "tenant" to "workspace" across the entire database schema.
 *
 * This migration aligns the database terminology with the user-facing
 * product language ("workspace") instead of the original internal term
 * ("tenant"). Changes include:
 *   - Rename the `tenants` table to `workspaces`
 *   - Rename `tenant_id` columns to `workspace_id` on all referencing tables
 *   - Recreate RLS policies using `app.workspace_id` session variable
 */
return new class extends Migration
{
    /**
     * Tables that have a direct `tenant_id` foreign key column.
     */
    private const FK_TABLES = [
        'users',
        'datasets',
        'dataset_rows',
        'pipeline_configs',
        'pipeline_jobs',
        'clusters',
        'knowledge_units',
        'exports',
        'llm_models',
        'embeddings',
        'knowledge_datasets',
        'chat_conversations',
        'invitations',
        'embedding_models',
        'answer_feedback',
    ];

    /**
     * Tables with direct workspace_id RLS policies.
     */
    private const RLS_DIRECT = [
        'datasets',
        'dataset_rows',
        'pipeline_jobs',
        'clusters',
        'knowledge_units',
        'llm_models',
        'exports',
        'knowledge_datasets',
        'chat_conversations',
    ];

    /**
     * Tables with indirect RLS via FK to a parent table.
     */
    private const RLS_INDIRECT = [
        'cluster_centroids'       => ['fk' => 'cluster_id', 'parent' => 'clusters'],
        'cluster_representatives' => ['fk' => 'cluster_id', 'parent' => 'clusters'],
        'cluster_memberships'     => ['fk' => 'cluster_id', 'parent' => 'clusters'],
        'knowledge_dataset_items' => ['fk' => 'knowledge_dataset_id', 'parent' => 'knowledge_datasets'],
        'chat_messages'           => ['fk' => 'conversation_id', 'parent' => 'chat_conversations'],
    ];

    public function up(): void
    {
        // ── 1. Drop all existing RLS policies (old tenant_id based) ──
        foreach (self::RLS_DIRECT as $table) {
            DB::statement("DROP POLICY IF EXISTS tenant_isolation_{$table} ON {$table}");
        }
        foreach (array_keys(self::RLS_INDIRECT) as $table) {
            DB::statement("DROP POLICY IF EXISTS tenant_isolation_{$table} ON {$table}");
        }

        // ── 2. Rename tenants table to workspaces ──
        Schema::rename('tenants', 'workspaces');

        // ── 3. Rename tenant_id to workspace_id on all referencing tables ──
        foreach (self::FK_TABLES as $table) {
            if (Schema::hasColumn($table, 'tenant_id')) {
                DB::statement("ALTER TABLE {$table} RENAME COLUMN tenant_id TO workspace_id");
            }
        }

        // ── 4. Recreate RLS policies using app.workspace_id ──
        foreach (self::RLS_DIRECT as $table) {
            DB::statement("
                CREATE POLICY workspace_isolation_{$table} ON {$table}
                USING (workspace_id = NULLIF(current_setting('app.workspace_id', true), '')::bigint)
            ");
        }

        foreach (self::RLS_INDIRECT as $table => $config) {
            DB::statement("
                CREATE POLICY workspace_isolation_{$table} ON {$table}
                USING ({$config['fk']} IN (
                    SELECT id FROM {$config['parent']}
                    WHERE workspace_id = NULLIF(current_setting('app.workspace_id', true), '')::bigint
                ))
            ");
        }
    }

    public function down(): void
    {
        // ── 1. Drop workspace RLS policies ──
        foreach (self::RLS_DIRECT as $table) {
            DB::statement("DROP POLICY IF EXISTS workspace_isolation_{$table} ON {$table}");
        }
        foreach (array_keys(self::RLS_INDIRECT) as $table) {
            DB::statement("DROP POLICY IF EXISTS workspace_isolation_{$table} ON {$table}");
        }

        // ── 2. Rename workspace_id back to tenant_id ──
        foreach (self::FK_TABLES as $table) {
            if (Schema::hasColumn($table, 'workspace_id')) {
                DB::statement("ALTER TABLE {$table} RENAME COLUMN workspace_id TO tenant_id");
            }
        }

        // ── 3. Rename workspaces table back to tenants ──
        Schema::rename('workspaces', 'tenants');

        // ── 4. Recreate original tenant RLS policies ──
        foreach (self::RLS_DIRECT as $table) {
            DB::statement("
                CREATE POLICY tenant_isolation_{$table} ON {$table}
                USING (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::bigint)
            ");
        }

        foreach (self::RLS_INDIRECT as $table => $config) {
            DB::statement("
                CREATE POLICY tenant_isolation_{$table} ON {$table}
                USING ({$config['fk']} IN (
                    SELECT id FROM {$config['parent']}
                    WHERE tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::bigint
                ))
            ");
        }
    }
};
