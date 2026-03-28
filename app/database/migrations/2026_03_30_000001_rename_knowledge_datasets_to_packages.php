<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 rename: align DB table/column names with PHP class names.
 *
 * Tables renamed:
 *   knowledge_datasets      → knowledge_packages
 *   knowledge_dataset_items → knowledge_package_items
 *
 * Columns renamed:
 *   knowledge_package_items.knowledge_dataset_id → knowledge_package_id
 *   chat_conversations.knowledge_dataset_id      → knowledge_package_id
 *
 * RLS policies that reference these tables/columns are dropped before the
 * rename and recreated afterward with updated names and SQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        // --- Drop existing RLS policies that reference the old names ---

        // Policies on knowledge_datasets (direct workspace isolation)
        DB::statement('DROP POLICY IF EXISTS tenant_isolation_knowledge_datasets ON knowledge_datasets');
        DB::statement('DROP POLICY IF EXISTS workspace_isolation_knowledge_datasets ON knowledge_datasets');

        // Policies on knowledge_dataset_items (indirect FK isolation)
        DB::statement('DROP POLICY IF EXISTS tenant_isolation_knowledge_dataset_items ON knowledge_dataset_items');
        DB::statement('DROP POLICY IF EXISTS workspace_isolation_knowledge_dataset_items ON knowledge_dataset_items');

        // --- Rename tables ---
        Schema::rename('knowledge_datasets', 'knowledge_packages');
        Schema::rename('knowledge_dataset_items', 'knowledge_package_items');

        // --- Rename FK column in knowledge_package_items ---
        Schema::table('knowledge_package_items', function (Blueprint $table) {
            $table->renameColumn('knowledge_dataset_id', 'knowledge_package_id');
        });

        // --- Rename FK column in chat_conversations ---
        Schema::table('chat_conversations', function (Blueprint $table) {
            $table->renameColumn('knowledge_dataset_id', 'knowledge_package_id');
        });

        // --- Recreate RLS policies with new table/column names ---

        // Direct workspace isolation on knowledge_packages
        DB::statement("
            CREATE POLICY workspace_isolation_knowledge_packages ON knowledge_packages
            USING (
                workspace_id = NULLIF(current_setting('app.workspace_id', true), '')::bigint
                OR current_setting('app.is_system_admin', true) = 'true'
            )
        ");

        // Indirect FK isolation on knowledge_package_items via knowledge_packages
        DB::statement("
            CREATE POLICY workspace_isolation_knowledge_package_items ON knowledge_package_items
            USING (
                knowledge_package_id IN (
                    SELECT id FROM knowledge_packages
                    WHERE workspace_id = NULLIF(current_setting('app.workspace_id', true), '')::bigint
                )
                OR current_setting('app.is_system_admin', true) = 'true'
            )
        ");
    }

    public function down(): void
    {
        // --- Drop the new RLS policies ---
        DB::statement('DROP POLICY IF EXISTS workspace_isolation_knowledge_packages ON knowledge_packages');
        DB::statement('DROP POLICY IF EXISTS workspace_isolation_knowledge_package_items ON knowledge_package_items');

        // --- Revert column renames ---
        Schema::table('chat_conversations', function (Blueprint $table) {
            $table->renameColumn('knowledge_package_id', 'knowledge_dataset_id');
        });

        Schema::table('knowledge_package_items', function (Blueprint $table) {
            $table->renameColumn('knowledge_package_id', 'knowledge_dataset_id');
        });

        // --- Revert table renames ---
        Schema::rename('knowledge_package_items', 'knowledge_dataset_items');
        Schema::rename('knowledge_packages', 'knowledge_datasets');

        // --- Restore original RLS policies ---
        DB::statement("
            CREATE POLICY workspace_isolation_knowledge_datasets ON knowledge_datasets
            USING (
                workspace_id = NULLIF(current_setting('app.workspace_id', true), '')::bigint
                OR current_setting('app.is_system_admin', true) = 'true'
            )
        ");

        DB::statement("
            CREATE POLICY workspace_isolation_knowledge_dataset_items ON knowledge_dataset_items
            USING (
                knowledge_dataset_id IN (
                    SELECT id FROM knowledge_datasets
                    WHERE workspace_id = NULLIF(current_setting('app.workspace_id', true), '')::bigint
                )
                OR current_setting('app.is_system_admin', true) = 'true'
            )
        ");
    }
};
