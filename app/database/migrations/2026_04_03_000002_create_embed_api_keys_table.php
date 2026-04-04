<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create the embed_api_keys table for widget/iframe authentication.
 *
 * CTO decisions:
 * - API Key is per-package (not per-workspace)
 * - DB stores SHA-256 hash only (plaintext shown once)
 * - Domain restriction is required
 * - Separate from Sanctum authentication
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('embed_api_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('knowledge_package_id')->constrained('knowledge_packages')->cascadeOnDelete();
            $table->string('key_hash', 64);          // SHA-256 hash
            $table->string('key_prefix', 8);          // First 8 chars for display (e.g. "kps_ab12")
            $table->jsonb('allowed_domains_json');     // ["example.com", "*.example.com"]
            $table->string('status', 20)->default('active'); // active | revoked
            $table->timestamp('expires_at')->nullable();
            $table->unsignedInteger('rate_limit_per_minute')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->unsignedBigInteger('total_requests')->default(0);
            $table->timestamps();

            $table->index(['key_hash']);
            $table->index(['workspace_id', 'status']);
            $table->unique(['knowledge_package_id', 'key_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('embed_api_keys');
    }
};
