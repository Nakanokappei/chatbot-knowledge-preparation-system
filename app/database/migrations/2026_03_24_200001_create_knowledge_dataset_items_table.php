<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Knowledge Dataset Items — links approved KUs to a dataset.
 *
 * Each item captures the KU version at the time of inclusion, ensuring
 * reproducibility even if the KU is later edited to a higher version.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_dataset_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('knowledge_dataset_id')
                  ->constrained('knowledge_datasets')
                  ->cascadeOnDelete();
            $table->foreignId('knowledge_unit_id')
                  ->constrained('knowledge_units')
                  ->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedInteger('included_version')->default(1);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['knowledge_dataset_id', 'knowledge_unit_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_dataset_items');
    }
};
