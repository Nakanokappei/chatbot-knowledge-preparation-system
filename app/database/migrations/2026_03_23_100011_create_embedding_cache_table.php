<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create the embedding_cache table.
 *
 * Caches embedding vectors by content hash to avoid redundant API calls.
 * Cache key: hash(normalized_text + normalization_version + model_name + dimension)
 * The actual embedding data is stored in S3; this table maps hash to S3 path.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('embedding_cache', function (Blueprint $table) {
            $table->id();
            $table->string('embedding_hash')->unique(); // SHA-256 of normalized_text + normalization_version + model + dimension
            $table->string('normalization_version');
            $table->string('model_name');
            $table->unsignedSmallInteger('dimension');
            $table->string('s3_path'); // path to the embedding vector file in S3
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('embedding_cache');
    }
};
