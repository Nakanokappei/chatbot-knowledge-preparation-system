<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Update all RLS policies to allow system_admin bypass.
 *
 * When SetWorkspaceScope middleware sets app.is_system_admin = 'true',
 * all RLS policies will allow full access regardless of workspace_id.
 *
 * For llm_models and embedding_models, rows with workspace_id IS NULL
 * (system templates) are visible to all users.
 */
return new class extends Migration
{
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

    /**
     * Tables whose system templates (workspace_id IS NULL) should be
     * visible to all authenticated users.
     */
    private const TEMPLATE_TABLES = ['llm_models', 'embedding_models'];

    public function up(): void
    {
        // Drop and recreate all direct RLS policies with system_admin bypass
        foreach (self::RLS_DIRECT as $table) {
            DB::statement("DROP POLICY IF EXISTS workspace_isolation_{$table} ON {$table}");

            // For template tables, also allow rows with NULL workspace_id
            if (in_array($table, self::TEMPLATE_TABLES)) {
                DB::statement("
                    CREATE POLICY workspace_isolation_{$table} ON {$table}
                    USING (
                        workspace_id = NULLIF(current_setting('app.workspace_id', true), '')::bigint
                        OR workspace_id IS NULL
                        OR current_setting('app.is_system_admin', true) = 'true'
                    )
                ");
            } else {
                DB::statement("
                    CREATE POLICY workspace_isolation_{$table} ON {$table}
                    USING (
                        workspace_id = NULLIF(current_setting('app.workspace_id', true), '')::bigint
                        OR current_setting('app.is_system_admin', true) = 'true'
                    )
                ");
            }
        }

        // Handle embedding_models separately (not in RLS_DIRECT but needs template visibility)
        DB::statement("DROP POLICY IF EXISTS workspace_isolation_embedding_models ON embedding_models");
        DB::statement("
            CREATE POLICY workspace_isolation_embedding_models ON embedding_models
            USING (
                workspace_id = NULLIF(current_setting('app.workspace_id', true), '')::bigint
                OR workspace_id IS NULL
                OR current_setting('app.is_system_admin', true) = 'true'
            )
        ");

        // Drop and recreate all indirect RLS policies with system_admin bypass
        foreach (self::RLS_INDIRECT as $table => $config) {
            DB::statement("DROP POLICY IF EXISTS workspace_isolation_{$table} ON {$table}");
            DB::statement("
                CREATE POLICY workspace_isolation_{$table} ON {$table}
                USING (
                    {$config['fk']} IN (
                        SELECT id FROM {$config['parent']}
                        WHERE workspace_id = NULLIF(current_setting('app.workspace_id', true), '')::bigint
                    )
                    OR current_setting('app.is_system_admin', true) = 'true'
                )
            ");
        }
    }

    public function down(): void
    {
        // Revert to original policies without system_admin bypass
        foreach (self::RLS_DIRECT as $table) {
            DB::statement("DROP POLICY IF EXISTS workspace_isolation_{$table} ON {$table}");
            DB::statement("
                CREATE POLICY workspace_isolation_{$table} ON {$table}
                USING (workspace_id = NULLIF(current_setting('app.workspace_id', true), '')::bigint)
            ");
        }

        DB::statement("DROP POLICY IF EXISTS workspace_isolation_embedding_models ON embedding_models");
        DB::statement("
            CREATE POLICY workspace_isolation_embedding_models ON embedding_models
            USING (workspace_id = NULLIF(current_setting('app.workspace_id', true), '')::bigint)
        ");

        foreach (self::RLS_INDIRECT as $table => $config) {
            DB::statement("DROP POLICY IF EXISTS workspace_isolation_{$table} ON {$table}");
            DB::statement("
                CREATE POLICY workspace_isolation_{$table} ON {$table}
                USING ({$config['fk']} IN (
                    SELECT id FROM {$config['parent']}
                    WHERE workspace_id = NULLIF(current_setting('app.workspace_id', true), '')::bigint
                ))
            ");
        }
    }
};
