<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rename the 'product' column to 'primary_filter' on knowledge_units.
 *
 * The field was originally called "product" but has been generalized
 * to serve as a configurable primary filter — product, region, department,
 * contract type, or any domain-appropriate entity.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_units', function (Blueprint $table) {
            $table->renameColumn('product', 'primary_filter');
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_units', function (Blueprint $table) {
            $table->renameColumn('primary_filter', 'product');
        });
    }
};
