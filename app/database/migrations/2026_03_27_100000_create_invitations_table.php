<?php

/**
 * Create invitations table for user-to-user invite flow.
 *
 * Each invitation holds a unique token, invitee email, and the tenant
 * the invitee will join upon registration. Tokens expire after 7 days.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
            $table->foreignId('invited_by')->constrained('users')->onDelete('cascade');
            $table->string('email');
            $table->string('token', 64)->unique();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->index(['email', 'tenant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitations');
    }
};
