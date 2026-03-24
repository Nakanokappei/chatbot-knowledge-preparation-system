<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Enable Row Level Security on Phase 4 tables.
 *
 * knowledge_datasets: direct tenant_id isolation
 * knowledge_dataset_items: indirect via FK to knowledge_datasets
 * chat_conversations: direct tenant_id isolation
 * chat_messages: indirect via FK to chat_conversations
 */
return new class extends Migration
{
    public function up(): void
    {
        // Direct tenant isolation tables
        $directTables = ['knowledge_datasets', 'chat_conversations'];

        foreach ($directTables as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement("
                CREATE POLICY tenant_isolation_{$table} ON {$table}
                USING (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::bigint)
            ");
        }

        // Indirect FK isolation tables
        $indirectTables = [
            'knowledge_dataset_items' => 'knowledge_datasets',
            'chat_messages'           => 'chat_conversations',
        ];

        foreach ($indirectTables as $child => $parent) {
            DB::statement("ALTER TABLE {$child} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$child} FORCE ROW LEVEL SECURITY");

            if ($child === 'knowledge_dataset_items') {
                DB::statement("
                    CREATE POLICY tenant_isolation_{$child} ON {$child}
                    USING (knowledge_dataset_id IN (
                        SELECT id FROM {$parent}
                        WHERE tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::bigint
                    ))
                ");
            } else {
                DB::statement("
                    CREATE POLICY tenant_isolation_{$child} ON {$child}
                    USING (conversation_id IN (
                        SELECT id FROM {$parent}
                        WHERE tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::bigint
                    ))
                ");
            }
        }
    }

    public function down(): void
    {
        $tables = [
            'chat_messages', 'chat_conversations',
            'knowledge_dataset_items', 'knowledge_datasets',
        ];

        foreach ($tables as $table) {
            DB::statement("DROP POLICY IF EXISTS tenant_isolation_{$table} ON {$table}");
            DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
        }
    }
};
