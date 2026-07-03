<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add an unguessable public token to chat_sessions for the embed widget.
 *
 * The embed chat API previously returned the auto-increment primary key as
 * session_id and resolved sessions by it, letting anyone with the (public)
 * embed key enumerate other anonymous visitors' conversations. The widget
 * now round-trips this random token instead of the numeric id.
 *
 * Nullable so existing rows and the authenticated workspace-chat flow (which
 * never exposes ids to third parties) are unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_sessions', function (Blueprint $table) {
            $table->string('public_token', 64)->nullable()->unique()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('chat_sessions', function (Blueprint $table) {
            $table->dropUnique(['public_token']);
            $table->dropColumn('public_token');
        });
    }
};
