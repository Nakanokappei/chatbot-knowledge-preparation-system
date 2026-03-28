<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Make workspace_id nullable in llm_models and embedding_models so that
 * rows with workspace_id = NULL can serve as system-wide templates.
 *
 * The RLS policies on both tables already allow NULL workspace_id rows to be
 * visible to all users (the "OR workspace_id IS NULL" clause), but the NOT NULL
 * constraint in the DDL blocked inserting template rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        // RLS policies reference workspace_id, which blocks ALTER COLUMN TYPE.
        // Drop policies, modify the column, then recreate the policies.

        DB::unprepared("
            -- Drop RLS policies that reference workspace_id
            DROP POLICY IF EXISTS workspace_isolation_llm_models ON llm_models;
            DROP POLICY IF EXISTS workspace_isolation_embedding_models ON embedding_models;

            -- Drop legacy FKs (named with tenant_id from original migration)
            ALTER TABLE llm_models DROP CONSTRAINT IF EXISTS llm_models_tenant_id_foreign;
            ALTER TABLE embedding_models DROP CONSTRAINT IF EXISTS embedding_models_tenant_id_foreign;

            -- Relax NOT NULL on both tables
            ALTER TABLE llm_models ALTER COLUMN workspace_id DROP NOT NULL;
            ALTER TABLE embedding_models ALTER COLUMN workspace_id DROP NOT NULL;

            -- Re-add FKs with ON DELETE SET NULL so workspace deletion nullifies template rows
            ALTER TABLE llm_models
                ADD CONSTRAINT llm_models_workspace_id_foreign
                FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE SET NULL;
            ALTER TABLE embedding_models
                ADD CONSTRAINT embedding_models_workspace_id_foreign
                FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE SET NULL;

            -- Recreate RLS policies (same logic: own workspace OR system template OR system admin)
            CREATE POLICY workspace_isolation_llm_models ON llm_models
                USING (
                    workspace_id = NULLIF(current_setting('app.workspace_id', true), '')::bigint
                    OR workspace_id IS NULL
                    OR current_setting('app.is_system_admin', true) = 'true'
                );

            CREATE POLICY workspace_isolation_embedding_models ON embedding_models
                USING (
                    workspace_id = NULLIF(current_setting('app.workspace_id', true), '')::bigint
                    OR workspace_id IS NULL
                    OR current_setting('app.is_system_admin', true) = 'true'
                );
        ");
    }

    public function down(): void
    {
        // Revert: delete any NULL-workspace rows first, then restore NOT NULL
        DB::table('llm_models')->whereNull('workspace_id')->delete();
        Schema::table('llm_models', function (Blueprint $table) {
            $table->dropForeign(['workspace_id']);
            $table->bigInteger('workspace_id')->nullable(false)->change();
            $table->foreign('workspace_id', 'llm_models_tenant_id_foreign')->references('id')->on('workspaces');
        });

        DB::table('embedding_models')->whereNull('workspace_id')->delete();
        Schema::table('embedding_models', function (Blueprint $table) {
            $table->dropForeign(['workspace_id']);
            $table->bigInteger('workspace_id')->nullable(false)->change();
            $table->foreign('workspace_id', 'embedding_models_tenant_id_foreign')->references('id')->on('workspaces');
        });
    }
};
