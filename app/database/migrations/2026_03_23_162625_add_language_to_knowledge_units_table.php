<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add language column to knowledge_units table.
 *
 * CTO Knowledge Unit definition includes language field.
 * Detected automatically by LLM during cluster analysis.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_units', function (Blueprint $table) {
            $table->string('language', 10)->nullable()->after('review_status');
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_units', function (Blueprint $table) {
            $table->dropColumn('language');
        });
    }
};
