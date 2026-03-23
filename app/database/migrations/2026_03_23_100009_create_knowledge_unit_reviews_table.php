<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create the knowledge_unit_reviews table.
 *
 * Tracks human review actions on Knowledge Units.
 * Each review transition (draft->reviewed, reviewed->approved, etc.)
 * is recorded as an immutable review entry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_unit_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('knowledge_unit_id')->constrained('knowledge_units')->cascadeOnDelete();
            $table->foreignId('reviewer_user_id')->constrained('users');
            $table->string('review_status'); // reviewed, approved, published
            $table->text('review_comment')->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_unit_reviews');
    }
};
