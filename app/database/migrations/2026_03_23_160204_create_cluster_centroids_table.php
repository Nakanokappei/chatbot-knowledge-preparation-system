<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Create cluster_centroids table.
 *
 * Stores the centroid vector for each cluster. CTO directive: required
 * for Phase 2 (new data classification, cluster distance calculation,
 * visualization, cluster merging, representative extraction for KU generation).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cluster_centroids', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cluster_id')->constrained('clusters')->cascadeOnDelete();
            $table->timestamps();
        });

        // Add pgvector column (not supported by Laravel Schema Builder)
        DB::statement('ALTER TABLE cluster_centroids ADD COLUMN centroid_vector vector(1024)');
    }

    public function down(): void
    {
        Schema::dropIfExists('cluster_centroids');
    }
};
