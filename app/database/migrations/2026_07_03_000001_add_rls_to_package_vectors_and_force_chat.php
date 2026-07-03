<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Close the remaining Row Level Security gaps for defense-in-depth.
 *
 * package_vectors had no RLS at all; it is isolated indirectly through the
 * owning knowledge_package's workspace_id. chat_sessions / chat_turns had RLS
 * ENABLEd and policied but not FORCEd, so the table-owning app DB user
 * bypassed the policy. Both tables are only ever touched after the request has
 * set app.workspace_id (authenticated web requests via SetWorkspaceScope, or
 * embed requests via EmbedApiKeyAuth), so enforcing RLS here is safe.
 *
 * NOTE: embed_api_keys is deliberately NOT brought under workspace RLS. That
 * table is looked up by key_hash *before* the workspace is known — the lookup
 * is what resolves the workspace — while SetWorkspaceScope has reset
 * app.workspace_id to ''. A workspace-scoped policy would make the auth lookup
 * match zero rows and break embed authentication entirely. embed_api_keys stays
 * protected at the application layer (BelongsToWorkspace scope on authenticated
 * management paths + explicit ownership checks in EmbedApiKeyController).
 */
return new class extends Migration
{
    public function up(): void
    {
        // package_vectors — indirect isolation via the owning knowledge_package
        DB::statement('ALTER TABLE package_vectors ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE package_vectors FORCE ROW LEVEL SECURITY');
        DB::statement("
            CREATE POLICY workspace_isolation_package_vectors ON package_vectors
            FOR ALL USING (
                package_id IN (
                    SELECT id FROM knowledge_packages
                    WHERE workspace_id = NULLIF(current_setting('app.workspace_id', true), '')::bigint
                )
                OR current_setting('app.is_system_admin', true) = 'true'
            )
        ");

        // chat_sessions / chat_turns — already ENABLEd + policied, add FORCE so
        // the table-owning app DB user cannot bypass the workspace policy.
        DB::statement('ALTER TABLE chat_sessions FORCE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE chat_turns FORCE ROW LEVEL SECURITY');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE chat_turns NO FORCE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE chat_sessions NO FORCE ROW LEVEL SECURITY');

        DB::statement('DROP POLICY IF EXISTS workspace_isolation_package_vectors ON package_vectors');
        DB::statement('ALTER TABLE package_vectors DISABLE ROW LEVEL SECURITY');
    }
};
