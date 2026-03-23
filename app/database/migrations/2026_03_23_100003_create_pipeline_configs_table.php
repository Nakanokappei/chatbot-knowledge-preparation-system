<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create the pipeline_configs table.
 *
 * Pipeline configs define the full parameter set for a pipeline run.
 * They serve as reusable templates; each job stores a snapshot copy
 * in pipeline_config_snapshot_json for reproducibility.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pipeline_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->string('name');
            $table->string('version')->default('v1');
            $table->jsonb('config_json'); // full pipeline config as defined in specs
            $table->timestamps();

            $table->index(['tenant_id']);
            $table->unique(['tenant_id', 'name', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pipeline_configs');
    }
};
