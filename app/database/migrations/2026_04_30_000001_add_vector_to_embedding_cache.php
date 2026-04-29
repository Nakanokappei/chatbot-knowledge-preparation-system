<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add a pgvector column to embedding_cache so embeddings can be stored
 * directly in the database instead of as individual S3 JSON files.
 *
 * Background: the original design wrote one S3 JSON object per unique
 * (text, model, dimension) tuple, with embedding_cache mapping
 * embedding_hash to s3_path. At ~34k cached entries this produced
 * ~1 GiB across ~34k tiny S3 objects, which is awkward to manage:
 *   - many small objects = poor S3 cost/latency profile
 *   - per-object TTL/retention is meaningless because the cache is
 *     keyed by a stable content hash and meant to be reused forever
 *   - cache reads pay one S3 GET per entry on top of the DB lookup
 *
 * Plan: introduce embedding_vector (untyped vector, matches the rest
 * of the schema's variable-dimension pattern), backfill from S3, then
 * drop s3_path and the S3 prefix in a follow-up migration once the
 * worker has been switched over.
 *
 * Variable-dimension `vector` (no parenthesised size) is used to stay
 * consistent with knowledge_units.embedding / cluster_centroids.centroid_vector
 * after the 2026_04_05 / 2026_04_06 migrations. It accepts any
 * dimension so future model swaps (Titan 1024 → OpenAI 1536/3072) do
 * not require another migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        // pgvector extension is already enabled by earlier migrations
        // (knowledge_units, cluster_centroids both use vector columns).
        DB::statement('ALTER TABLE embedding_cache ADD COLUMN IF NOT EXISTS embedding_vector vector');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE embedding_cache DROP COLUMN IF EXISTS embedding_vector');
    }
};
