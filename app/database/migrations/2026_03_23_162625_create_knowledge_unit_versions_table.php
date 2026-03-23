<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Expand knowledge_unit_versions table to CTO specification.
 *
 * CTO directive: "Knowledge は必ず更新されるため、Knowledge Versioning は重要機能"
 * Adds structured fields for topic, intent, summary, keywords, language.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_unit_versions', function (Blueprint $table) {
            $table->string('topic')->nullable()->after('version');
            $table->string('intent')->nullable()->after('topic');
            $table->text('summary')->nullable()->after('intent');
            $table->jsonb('representative_examples_json')->nullable()->after('summary');
            $table->jsonb('keywords_json')->nullable()->after('representative_examples_json');
            $table->string('language', 10)->nullable()->after('keywords_json');
            $table->string('change_reason')->nullable()->after('language');
            $table->timestamp('updated_at')->nullable()->after('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_unit_versions', function (Blueprint $table) {
            $table->dropColumn(['topic', 'intent', 'summary', 'representative_examples_json', 'keywords_json', 'language', 'change_reason', 'updated_at']);
        });
    }
};
