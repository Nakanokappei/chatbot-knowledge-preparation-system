<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Allow cause_summary and resolution_summary to be NULL.
 *
 * LLM-based knowledge extraction may not always produce these fields,
 * so the NOT NULL constraint must be relaxed to avoid pipeline failures.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE knowledge_units ALTER COLUMN cause_summary DROP NOT NULL');
        DB::statement('ALTER TABLE knowledge_units ALTER COLUMN resolution_summary DROP NOT NULL');
    }

    public function down(): void
    {
        DB::statement("UPDATE knowledge_units SET cause_summary = '' WHERE cause_summary IS NULL");
        DB::statement("UPDATE knowledge_units SET resolution_summary = '' WHERE resolution_summary IS NULL");
        DB::statement('ALTER TABLE knowledge_units ALTER COLUMN cause_summary SET NOT NULL');
        DB::statement('ALTER TABLE knowledge_units ALTER COLUMN resolution_summary SET NOT NULL');
    }
};
