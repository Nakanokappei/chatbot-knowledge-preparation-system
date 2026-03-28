<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 4 rename: align status value terminology with the UI.
 *
 * In the UI, "pending_review" is displayed as "公開申請中 / Publication Requested".
 * The DB value is renamed to match so that code reads naturally without translation.
 *
 * knowledge_packages.status: 'pending_review' → 'publication_requested'
 *
 * Note: all data should be cleared before running this migration.
 * The UPDATE is included for safety in case any rows remain.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('knowledge_packages')
            ->where('status', 'pending_review')
            ->update(['status' => 'publication_requested']);
    }

    public function down(): void
    {
        DB::table('knowledge_packages')
            ->where('status', 'publication_requested')
            ->update(['status' => 'pending_review']);
    }
};
