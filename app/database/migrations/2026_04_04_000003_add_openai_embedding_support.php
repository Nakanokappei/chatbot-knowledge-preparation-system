<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add OpenAI embedding model support:
 * 1. system_settings table for storing OpenAI API key (encrypted)
 * 2. provider column on embedding_models to distinguish bedrock/openai
 */
return new class extends Migration
{
    public function up(): void
    {
        // Key-value store for system-wide settings (e.g. API keys)
        Schema::create('system_settings', function (Blueprint $table) {
            $table->string('key', 100)->primary();
            $table->text('value')->nullable(); // Encrypted at application level
            $table->timestamps();
        });

        // Add provider column to embedding_models
        Schema::table('embedding_models', function (Blueprint $table) {
            $table->string('provider', 20)->default('bedrock')->after('model_id');
        });
    }

    public function down(): void
    {
        Schema::table('embedding_models', function (Blueprint $table) {
            $table->dropColumn('provider');
        });

        Schema::dropIfExists('system_settings');
    }
};
