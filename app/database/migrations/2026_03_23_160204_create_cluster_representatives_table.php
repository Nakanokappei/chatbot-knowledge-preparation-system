<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create cluster_representatives table.
 *
 * Stores representative rows for each cluster, ranked by distance to centroid.
 * CTO directive: required for Phase 2 Knowledge Unit generation
 * (topic naming, summarization, typical case extraction).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cluster_representatives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cluster_id')->constrained('clusters')->cascadeOnDelete();
            $table->foreignId('dataset_row_id')->constrained('dataset_rows')->cascadeOnDelete();
            $table->decimal('distance_to_centroid', 10, 6);
            $table->integer('rank');  // 1 = closest to centroid
            $table->timestamps();

            $table->unique(['cluster_id', 'dataset_row_id']);
            $table->index(['cluster_id', 'rank']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cluster_representatives');
    }
};
