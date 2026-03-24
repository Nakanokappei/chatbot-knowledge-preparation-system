<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add IVFFlat cosine distance index on knowledge_units.embedding.
 *
 * IVFFlat with lists=10 is suitable for < 10,000 KUs.
 * For larger datasets, consider migrating to HNSW index in Phase 5.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('
            CREATE INDEX IF NOT EXISTS idx_knowledge_units_embedding_cosine
            ON knowledge_units
            USING ivfflat (embedding vector_cosine_ops)
            WITH (lists = 10)
        ');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_knowledge_units_embedding_cosine');
    }
};
