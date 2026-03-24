<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Knowledge Datasets — versioned collections of approved Knowledge Units.
 *
 * CTO-defined statuses: draft (editing), published (chatbot-active), archived (retired).
 * Published datasets are immutable; new versions clone items into a draft.
 * Retrieval is only performed against published datasets.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_datasets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->string('status')->default('draft'); // draft, published, archived
            $table->jsonb('source_job_ids')->nullable();   // [{job_id, dataset_name}]
            $table->unsignedInteger('ku_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_datasets');
    }
};
