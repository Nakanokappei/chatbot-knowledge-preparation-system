<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add embedding model binding to knowledge packages and create the
 * package_vectors table for independent vector index storage.
 *
 * Architecture: Knowledge Package = Vector Index.
 * Each package has a single embedding model. On publish, all KU vectors
 * are (re)generated using that model and stored in package_vectors.
 * Search queries use this table instead of joining through KU embeddings.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Bind each package to a specific embedding model
        Schema::table('knowledge_packages', function (Blueprint $table) {
            $table->foreignId('embedding_model_id')->nullable()->after('embed_config_json')
                ->constrained('embedding_models')->nullOnDelete();
        });

        // Package vector index: stores pre-computed vectors per KU per package.
        // Uses pgvector without fixed dimensions to support multiple models.
        Schema::create('package_vectors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_id')->constrained('knowledge_packages')->cascadeOnDelete();
            $table->foreignId('knowledge_unit_id')->constrained('knowledge_units')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['package_id', 'knowledge_unit_id']);
            $table->index('package_id');
        });

        // Add pgvector columns (no fixed dimension — supports 1024, 1536, 3072, etc.)
        DB::statement('ALTER TABLE package_vectors ADD COLUMN search_vector vector');
        DB::statement('ALTER TABLE package_vectors ADD COLUMN broad_vector vector');
    }

    public function down(): void
    {
        Schema::dropIfExists('package_vectors');

        Schema::table('knowledge_packages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('embedding_model_id');
        });
    }
};
