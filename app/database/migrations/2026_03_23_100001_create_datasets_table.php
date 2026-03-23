<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create the datasets table.
 *
 * A dataset represents an uploaded file (CSV/TSV) containing
 * customer support logs to be processed through the pipeline.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('datasets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->string('name');
            $table->string('source_type')->default('csv'); // csv, json, api
            $table->string('original_filename')->nullable();
            $table->string('s3_raw_path')->nullable();
            $table->unsignedInteger('row_count')->default(0);
            $table->jsonb('schema_json')->nullable(); // column structure of the source file
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('datasets');
    }
};
