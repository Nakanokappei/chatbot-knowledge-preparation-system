<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ensure FORCE ROW LEVEL SECURITY on all tenant-scoped tables.
 *
 * CTO directive: all 14 tenant tables must have FORCE RLS.
 * This ensures RLS is enforced even for superuser connections,
 * preventing accidental cross-tenant data access.
 *
 * Production DB user must NOT be superuser (standard RDS practice).
 */
return new class extends Migration
{
    private const TABLES = [
        'datasets',
        'dataset_rows',
        'pipeline_jobs',
        'clusters',
        'cluster_memberships',
        'cluster_centroids',
        'cluster_representatives',
        'knowledge_units',
        'knowledge_unit_versions',
        'knowledge_unit_reviews',
        'knowledge_datasets',
        'knowledge_dataset_items',
        'chat_conversations',
        'chat_messages',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            DB::statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
        }
    }
};
