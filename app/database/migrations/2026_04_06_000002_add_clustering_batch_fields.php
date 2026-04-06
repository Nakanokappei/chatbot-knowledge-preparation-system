<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add support for "clustering-only" pipeline jobs.
 *
 * start_step: which pipeline step to begin at (default: 'preprocess' = full pipeline).
 *   Set to 'clustering' to skip preprocess+embedding and re-use existing vectors.
 *
 * source_job_id: references a completed job whose embedding output (S3 path) to reuse.
 *   Only relevant when start_step != 'preprocess'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pipeline_jobs', function (Blueprint $table) {
            $table->string('start_step', 50)->default('preprocess')->after('status');
            $table->unsignedBigInteger('source_job_id')->nullable()->after('start_step');

            // Self-referencing FK: if the source job is deleted, set null
            $table->foreign('source_job_id')
                ->references('id')
                ->on('pipeline_jobs')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pipeline_jobs', function (Blueprint $table) {
            $table->dropForeign(['source_job_id']);
            $table->dropColumn(['start_step', 'source_job_id']);
        });
    }
};
