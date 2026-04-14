<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add `current_action` column to pipeline_jobs.
 *
 * Stores a short human-readable description of what the worker is doing
 * right now (e.g., "Generating embeddings (123/500)", "Writing clusters to DB").
 * The UI surfaces this alongside the progress bar so users can see real-time
 * activity between coarse %-based progress updates.
 *
 * Nullable so legacy jobs and non-running states (completed/failed/cancelled)
 * keep a NULL value. Worker sets it at each heartbeat via update_job_action().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pipeline_jobs', function (Blueprint $table) {
            $table->string('current_action', 255)->nullable()->after('progress');
        });
    }

    public function down(): void
    {
        Schema::table('pipeline_jobs', function (Blueprint $table) {
            $table->dropColumn('current_action');
        });
    }
};
