<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add knowledge_package_id to chat_sessions and make embedding_id nullable.
 *
 * This allows the same chat engine (processChat + ChatSession/ChatTurn)
 * to be used for both workspace embedding chat and embed widget chat.
 * A session targets either an embedding OR a package, never both.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Make embedding_id nullable (embed widget sessions have no embedding)
        DB::statement('ALTER TABLE chat_sessions ALTER COLUMN embedding_id DROP NOT NULL');

        Schema::table('chat_sessions', function (Blueprint $table) {
            $table->foreignId('knowledge_package_id')->nullable()->after('embedding_id')
                ->constrained('knowledge_packages')->nullOnDelete();
            $table->index(['knowledge_package_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('chat_sessions', function (Blueprint $table) {
            $table->dropForeign(['knowledge_package_id']);
            $table->dropColumn('knowledge_package_id');
        });

        // Delete sessions without embedding_id before restoring NOT NULL
        DB::statement("DELETE FROM chat_sessions WHERE embedding_id IS NULL");
        DB::statement('ALTER TABLE chat_sessions ALTER COLUMN embedding_id SET NOT NULL');
    }
};
