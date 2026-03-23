<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create the dataset_rows table.
 *
 * Each row stores one support log entry from the uploaded dataset.
 * normalized_text and embedding_hash are populated during preprocessing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dataset_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dataset_id')->constrained('datasets')->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->unsignedInteger('row_no'); // original row number in the source file
            $table->text('raw_text');
            $table->text('normalized_text')->nullable();
            $table->jsonb('metadata_json')->nullable();
            $table->string('embedding_hash')->nullable(); // hash for embedding cache lookup
            $table->timestamps();

            $table->index(['dataset_id', 'tenant_id']);
            $table->index('embedding_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dataset_rows');
    }
};
