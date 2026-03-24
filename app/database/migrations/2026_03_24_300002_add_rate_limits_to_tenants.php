<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add rate limit and budget columns to tenants table.
 *
 * CTO budget enforcement tiers:
 * - 80%: warning banner
 * - 100%: chat API stop
 * - 120%: all API stop except export
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->unsignedInteger('retrieve_rate_limit')->default(60)->after('name');  // per minute
            $table->unsignedInteger('chat_rate_limit')->default(20)->after('retrieve_rate_limit');  // per minute
            $table->unsignedBigInteger('monthly_token_budget')->default(1000000)->after('chat_rate_limit');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['retrieve_rate_limit', 'chat_rate_limit', 'monthly_token_budget']);
        });
    }
};
