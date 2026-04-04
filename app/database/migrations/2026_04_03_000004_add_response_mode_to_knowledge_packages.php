<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add response_mode and embed_config_json to knowledge_packages.
 *
 * Package-level response_mode: 'default_answer' | 'prefer_link' | 'link_only'
 * Controls how the chatbot responds for the entire package.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_packages', function (Blueprint $table) {
            $table->string('response_mode', 20)->default('default_answer')->after('status');
            $table->jsonb('embed_config_json')->nullable()->after('response_mode');
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_packages', function (Blueprint $table) {
            $table->dropColumn(['response_mode', 'embed_config_json']);
        });
    }
};
