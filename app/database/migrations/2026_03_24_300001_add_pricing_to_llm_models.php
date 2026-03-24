<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add pricing columns to llm_models for cost calculation.
 *
 * Prices are per 1 million tokens in USD (Bedrock ap-northeast-1 pricing).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('llm_models', function (Blueprint $table) {
            $table->decimal('input_price_per_1m', 8, 4)->default(1.00)->after('is_active');
            $table->decimal('output_price_per_1m', 8, 4)->default(5.00)->after('input_price_per_1m');
        });
    }

    public function down(): void
    {
        Schema::table('llm_models', function (Blueprint $table) {
            $table->dropColumn(['input_price_per_1m', 'output_price_per_1m']);
        });
    }
};
