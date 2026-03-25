<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add knowledge structure fields to knowledge_units.
 *
 * These fields support the chatbot retrieval flow:
 * - question: the generated FAQ-style question for similarity search
 * - symptoms: surface-level symptoms/error messages extracted by LLM
 * - root_cause: underlying cause extracted from resolution data
 * - product: product or service name
 * - category: classification tag
 * - search_embedding: vector of the question field for retrieval
 * - column_mapping_json: stores the Configure page mapping config
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_units', function (Blueprint $table) {
            $table->text('question')->nullable()->after('summary');
            $table->text('symptoms')->nullable()->after('question');
            $table->text('root_cause')->nullable()->after('symptoms');
            $table->string('product', 255)->nullable()->after('root_cause');
            $table->string('category', 255)->nullable()->after('product');
        });

        // Add search_embedding vector column via raw SQL (pgvector)
        DB::statement('ALTER TABLE knowledge_units ADD COLUMN IF NOT EXISTS search_embedding vector(1024)');

        // Add index for cosine similarity search on question embeddings
        DB::statement('CREATE INDEX IF NOT EXISTS idx_ku_search_embedding_cosine ON knowledge_units USING ivfflat (search_embedding vector_cosine_ops) WITH (lists = 10)');

        // Store the column mapping configuration from the Configure page
        Schema::table('datasets', function (Blueprint $table) {
            $table->jsonb('knowledge_mapping_json')->nullable()->after('schema_json');
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_units', function (Blueprint $table) {
            $table->dropColumn(['question', 'symptoms', 'root_cause', 'product', 'category']);
        });

        DB::statement('DROP INDEX IF EXISTS idx_ku_search_embedding_cosine');
        DB::statement('ALTER TABLE knowledge_units DROP COLUMN IF EXISTS search_embedding');

        Schema::table('datasets', function (Blueprint $table) {
            $table->dropColumn('knowledge_mapping_json');
        });
    }
};
