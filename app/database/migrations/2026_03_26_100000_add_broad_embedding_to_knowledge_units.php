<?php

/**
 * Add broad_embedding column for two-stage vector search.
 *
 * search_embedding: derived from question only (precise match)
 * broad_embedding: derived from question + topic + symptoms + summary (wide recall)
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Add broad_embedding vector column
        DB::statement('ALTER TABLE knowledge_units ADD COLUMN IF NOT EXISTS broad_embedding vector(1024)');

        // Create ivfflat index for cosine similarity search
        DB::statement('CREATE INDEX IF NOT EXISTS idx_ku_broad_embedding_cosine ON knowledge_units USING ivfflat (broad_embedding vector_cosine_ops) WITH (lists = 10)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_ku_broad_embedding_cosine');
        DB::statement('ALTER TABLE knowledge_units DROP COLUMN IF EXISTS broad_embedding');
    }
};
