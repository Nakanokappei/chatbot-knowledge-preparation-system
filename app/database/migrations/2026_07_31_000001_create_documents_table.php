<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Create the documents table for uploaded source documents (PDF).
 *
 * A document is a raw file kept in object storage as-is. Nothing is
 * extracted or embedded yet — this table only records what was uploaded,
 * by whom, and where the bytes live.
 *
 * stored_path never contains the client-supplied filename: the stored name
 * is a random token, and the original name is kept in original_filename for
 * display only. This keeps user input out of the storage path entirely.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            // Keep the document when its uploader is removed from the workspace
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('original_filename');
            $table->string('stored_path', 500);
            $table->unsignedBigInteger('byte_size');
            $table->timestamps();

            $table->index(['workspace_id', 'created_at']);
        });

        // Row Level Security — direct workspace isolation, matching the
        // policy shape used by datasets and the other tenant tables.
        DB::statement('ALTER TABLE documents ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE documents FORCE ROW LEVEL SECURITY');
        DB::statement("
            CREATE POLICY workspace_isolation_documents ON documents
            USING (
                workspace_id = NULLIF(current_setting('app.workspace_id', true), '')::bigint
                OR current_setting('app.is_system_admin', true) = 'true'
            )
        ");
    }

    public function down(): void
    {
        DB::statement('DROP POLICY IF EXISTS workspace_isolation_documents ON documents');
        Schema::dropIfExists('documents');
    }
};
