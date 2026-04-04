<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add reference_url and response_mode to knowledge_units for Link Guidance Mode.
 *
 * response_mode per KU: 'answer' (default, normal RAG) | 'link_only' (return URL, skip LLM)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_units', function (Blueprint $table) {
            $table->string('reference_url', 2048)->nullable()->after('notes');
            $table->string('response_mode', 20)->default('answer')->after('reference_url');
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_units', function (Blueprint $table) {
            $table->dropColumn(['reference_url', 'response_mode']);
        });
    }
};
