<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create the cluster_memberships table.
 *
 * Junction table linking dataset_rows to clusters.
 * membership_score supports soft clustering where a row
 * may belong to multiple clusters with varying confidence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cluster_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cluster_id')->constrained('clusters')->cascadeOnDelete();
            $table->foreignId('dataset_row_id')->constrained('dataset_rows')->cascadeOnDelete();
            $table->decimal('membership_score', 5, 4)->nullable();
            $table->timestamps();

            $table->unique(['cluster_id', 'dataset_row_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cluster_memberships');
    }
};
