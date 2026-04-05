<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Remove the fixed 1024-dimension constraint from KU vector columns.
 *
 * This allows storing embeddings from different models with different
 * dimensions (e.g., Titan V2 1024d, OpenAI small 1536d, OpenAI large 3072d).
 * pgvector supports untyped `vector` columns that accept any dimension.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Drop existing ivfflat indexes (they require fixed dimensions)
        DB::statement('DROP INDEX IF EXISTS idx_ku_broad_embedding_cosine');
        DB::statement('DROP INDEX IF EXISTS knowledge_units_embedding_idx');

        // Change columns from vector(1024) to vector (no dimension constraint)
        DB::statement('ALTER TABLE knowledge_units ALTER COLUMN embedding TYPE vector USING embedding::vector');
        DB::statement('ALTER TABLE knowledge_units ALTER COLUMN search_embedding TYPE vector USING search_embedding::vector');
        DB::statement('ALTER TABLE knowledge_units ALTER COLUMN broad_embedding TYPE vector USING broad_embedding::vector');
    }

    public function down(): void
    {
        // Restore fixed 1024-dimension columns
        DB::statement('ALTER TABLE knowledge_units ALTER COLUMN embedding TYPE vector(1024) USING embedding::vector(1024)');
        DB::statement('ALTER TABLE knowledge_units ALTER COLUMN search_embedding TYPE vector(1024) USING search_embedding::vector(1024)');
        DB::statement('ALTER TABLE knowledge_units ALTER COLUMN broad_embedding TYPE vector(1024) USING broad_embedding::vector(1024)');

        // Recreate indexes
        DB::statement('CREATE INDEX IF NOT EXISTS idx_ku_broad_embedding_cosine ON knowledge_units USING ivfflat (broad_embedding vector_cosine_ops) WITH (lists = 10)');
    }
};
