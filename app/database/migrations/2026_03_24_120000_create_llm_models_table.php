<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create the llm_models table for user-manageable LLM model registry.
 *
 * Models become obsolete quickly and new ones appear frequently.
 * This table allows users to add, remove, and configure LLM models
 * from the settings UI without touching code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('llm_models', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->string('display_name');
            $table->string('model_id');
            $table->boolean('is_default')->default(false);
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'is_active']);
            $table->unique(['tenant_id', 'model_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('llm_models');
    }
};
