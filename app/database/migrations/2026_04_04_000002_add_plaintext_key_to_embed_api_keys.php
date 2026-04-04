<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Store plaintext API key in embed_api_keys.
 *
 * API keys are embedded in public <script> tags on customer websites,
 * so they are NOT secret. Storing plaintext allows the dashboard to
 * show embed code snippets for any active key at any time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('embed_api_keys', function (Blueprint $table) {
            $table->string('api_key', 52)->nullable()->after('key_prefix');
        });

        // Backfill: existing keys have no plaintext stored.
        // They must be regenerated (revoke + create new).
    }

    public function down(): void
    {
        Schema::table('embed_api_keys', function (Blueprint $table) {
            $table->dropColumn('api_key');
        });
    }
};
