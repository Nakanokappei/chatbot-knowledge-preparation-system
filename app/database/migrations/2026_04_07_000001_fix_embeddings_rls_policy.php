<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fix the embeddings RLS policy that still references the legacy app.tenant_id
 * setting instead of app.workspace_id.
 *
 * Root cause: the tenant→workspace rename migration updated most tables but
 * missed the embeddings policy. This caused FK cascade operations involving
 * embeddings to fail silently under RLS, leading to unexpected data loss
 * when deleting pipeline jobs.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("DROP POLICY IF EXISTS tenant_isolation_embeddings ON embeddings");
        DB::statement("DROP POLICY IF EXISTS workspace_isolation_embeddings ON embeddings");
        DB::statement("
            CREATE POLICY workspace_isolation_embeddings ON embeddings
            FOR ALL USING (
                workspace_id = NULLIF(current_setting('app.workspace_id', true), '')::bigint
                OR current_setting('app.is_system_admin', true) = 'true'
            )
        ");
    }

    public function down(): void
    {
        DB::statement("DROP POLICY IF EXISTS workspace_isolation_embeddings ON embeddings");
        DB::statement("
            CREATE POLICY tenant_isolation_embeddings ON embeddings
            FOR ALL USING (
                workspace_id = NULLIF(current_setting('app.tenant_id', true), '')::bigint
            )
        ");
    }
};
