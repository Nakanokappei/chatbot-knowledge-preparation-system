<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create the knowledge_unit_versions table.
 *
 * Stores immutable snapshots of Knowledge Units for audit trail.
 * Each edit or re-generation creates a new version record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_unit_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('knowledge_unit_id')->constrained('knowledge_units')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->jsonb('snapshot_json'); // full KU state at this version
            $table->timestamp('created_at');

            $table->unique(['knowledge_unit_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_unit_versions');
    }
};
