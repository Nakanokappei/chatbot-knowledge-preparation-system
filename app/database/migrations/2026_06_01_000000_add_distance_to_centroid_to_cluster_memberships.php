<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add distance_to_centroid to cluster_memberships.
 *
 * cluster_representatives already stores distance_to_centroid, but only for
 * the top-5 rows closest to each centroid (the LLM-summary "representatives").
 * To export a per-row distance for EVERY clustered row, we record the distance
 * on the membership itself — the only table that holds one row per clustered
 * dataset_row.
 *
 * Same units/space as cluster_representatives: the worker computes this with
 * the SAME embeddings array used to compute the centroid (after language
 * debiasing + unit-normalisation), so the value is directly comparable to the
 * representatives' distances and to cluster_centroids. NULL until the next
 * clustering run populates it; existing memberships stay NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cluster_memberships', function (Blueprint $table) {
            $table->decimal('distance_to_centroid', 10, 6)->nullable()->after('membership_score');
        });
    }

    public function down(): void
    {
        Schema::table('cluster_memberships', function (Blueprint $table) {
            $table->dropColumn('distance_to_centroid');
        });
    }
};
