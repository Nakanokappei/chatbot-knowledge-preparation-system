<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Remove the fixed 1024-dimension constraint from cluster_centroids.
 *
 * The previous variable-dimension migration (2026_04_05_000002) covered
 * knowledge_units but missed cluster_centroids, which also stores
 * embedding vectors that vary by model (1024d, 1536d, 3072d).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE cluster_centroids ALTER COLUMN centroid_vector TYPE vector USING centroid_vector::vector');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE cluster_centroids ALTER COLUMN centroid_vector TYPE vector(1024) USING centroid_vector::vector(1024)');
    }
};
