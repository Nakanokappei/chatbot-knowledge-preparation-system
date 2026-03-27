<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add system_admin role support:
 *   - Make users.workspace_id nullable (system admins have no workspace)
 *   - Add role column to invitations table
 *   - Promote nakano.kappei@gmail.com to system_admin
 */
return new class extends Migration
{
    public function up(): void
    {
        // Allow workspace_id to be NULL for system administrators
        DB::statement('ALTER TABLE users ALTER COLUMN workspace_id DROP NOT NULL');

        // Allow invitations.workspace_id to be NULL for system_admin invitations
        DB::statement('ALTER TABLE invitations ALTER COLUMN workspace_id DROP NOT NULL');

        // Allow llm_models and embedding_models workspace_id to be NULL for system templates
        DB::statement('ALTER TABLE llm_models ALTER COLUMN workspace_id DROP NOT NULL');
        DB::statement('ALTER TABLE embedding_models ALTER COLUMN workspace_id DROP NOT NULL');

        // Add role column to invitations so the inviter can choose which role
        // the invited user will receive upon registration
        if (!Schema::hasColumn('invitations', 'role')) {
            DB::statement("ALTER TABLE invitations ADD COLUMN role VARCHAR(20) NOT NULL DEFAULT 'member'");
        }

        // Promote the designated user to system_admin with no workspace binding
        DB::table('users')
            ->where('email', 'nakano.kappei@gmail.com')
            ->update([
                'role' => 'system_admin',
                'workspace_id' => null,
            ]);
    }

    public function down(): void
    {
        // Revert the promoted user back to owner in their original workspace
        // (cannot reliably restore workspace_id, so skip that)
        DB::table('users')
            ->where('email', 'nakano.kappei@gmail.com')
            ->update(['role' => 'owner']);

        // Remove role column from invitations
        if (Schema::hasColumn('invitations', 'role')) {
            Schema::table('invitations', function ($table) {
                $table->dropColumn('role');
            });
        }
    }
};
