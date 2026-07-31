<?php

namespace App\Http\Controllers;

use App\Models\Document;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Upload and manage source documents (PDF).
 *
 * Files are stored verbatim on the documents disk and recorded in the
 * documents table. No text extraction or embedding happens here — that
 * belongs to a later pipeline stage.
 */
class DocumentController extends Controller
{
    /**
     * Accepted upload size, in kilobytes (50 MB).
     *
     * This is both the per-file and the per-submission total limit: PHP is
     * configured with post_max_size = 52M (see app/Dockerfile), and a request
     * larger than that is discarded before Laravel ever sees it — which would
     * surface as a CSRF failure rather than a validation message.
     */
    private const MAX_UPLOAD_KILOBYTES = 51200;

    /** Maximum number of files accepted in one submission. */
    private const MAX_FILES_PER_UPLOAD = 20;

    /**
     * List the workspace's uploaded documents, newest first.
     */
    public function index(): View
    {
        $documents = Document::with('uploader:id,name')
            ->orderByDesc('created_at')
            ->get();

        return view('documents.index', [
            'documents' => $documents,
            'maxFiles' => self::MAX_FILES_PER_UPLOAD,
            'maxMegabytes' => (int) (self::MAX_UPLOAD_KILOBYTES / 1024),
            'maxTotalBytes' => self::MAX_UPLOAD_KILOBYTES * 1024,
        ]);
    }

    /**
     * Accept one or more PDF uploads and store them on the documents disk.
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'documents' => 'required|array|min:1|max:' . self::MAX_FILES_PER_UPLOAD,
            'documents.*' => 'required|file|mimes:pdf|max:' . self::MAX_UPLOAD_KILOBYTES,
        ]);

        // Enforce the combined size as well: several files can each pass the
        // per-file rule while together exceeding what PHP will accept.
        $totalBytes = array_sum(array_map(
            fn($file) => $file->getSize(),
            $request->file('documents'),
        ));
        if ($totalBytes > self::MAX_UPLOAD_KILOBYTES * 1024) {
            return redirect()->route('documents.index')
                ->with('error', __('ui.documents_upload_too_large', [
                    'size' => (int) (self::MAX_UPLOAD_KILOBYTES / 1024),
                ]));
        }

        $workspaceId = auth()->user()->workspace_id;
        $stored = 0;
        $rejected = [];

        foreach ($request->file('documents') as $file) {
            // Reject anything that is not really a PDF. The mimes rule already
            // inspects content, but the magic number is the cheap final word.
            if (!$this->hasPdfSignature($file->getRealPath())) {
                $rejected[] = $file->getClientOriginalName();
                continue;
            }

            // The stored name is a random token: the client-supplied filename
            // never reaches the storage path. The original name is kept in the
            // database for display only.
            $storedPath = sprintf('documents/workspace%d/%s.pdf', $workspaceId, Str::uuid());

            Storage::disk('documents')->put($storedPath, file_get_contents($file->getRealPath()));

            Document::create([
                'workspace_id' => $workspaceId,
                'uploaded_by' => auth()->id(),
                'original_filename' => mb_substr($file->getClientOriginalName(), 0, 255),
                'stored_path' => $storedPath,
                'byte_size' => $file->getSize(),
            ]);

            $stored++;
        }

        // Report partial success honestly: some files may have been rejected
        if (!empty($rejected)) {
            Log::warning('Rejected non-PDF uploads', ['files' => $rejected]);

            return redirect()->route('documents.index')
                ->with('error', __('ui.documents_upload_rejected', [
                    'count' => $stored,
                    'files' => implode(', ', $rejected),
                ]));
        }

        return redirect()->route('documents.index')
            ->with('success', __('ui.documents_upload_success', ['count' => $stored]));
    }

    /**
     * Stream a stored document back to the browser under its original name.
     *
     * Workspace isolation comes from the BelongsToWorkspace global scope,
     * which makes route model binding miss documents from other workspaces.
     */
    public function download(Document $document): StreamedResponse
    {
        $disk = Storage::disk('documents');

        if (!$disk->exists($document->stored_path)) {
            abort(404, 'Document file not found.');
        }

        return $disk->download($document->stored_path, $document->original_filename);
    }

    /**
     * Delete a document and its stored file.
     */
    public function destroy(Document $document): RedirectResponse
    {
        $disk = Storage::disk('documents');

        // Remove the stored bytes first; a missing file must not block the
        // record deletion, so failures here are logged rather than raised.
        try {
            if ($disk->exists($document->stored_path)) {
                $disk->delete($document->stored_path);
            }
        } catch (\Exception $e) {
            Log::warning('Failed to delete stored document file', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
            ]);
        }

        $document->delete();

        return redirect()->route('documents.index')
            ->with('success', __('ui.documents_delete_success'));
    }

    /**
     * Check the PDF magic number (%PDF-) at the start of the file.
     */
    private function hasPdfSignature(string $path): bool
    {
        $handle = fopen($path, 'rb');
        if (!$handle) {
            return false;
        }

        $header = fread($handle, 5);
        fclose($handle);

        return $header === '%PDF-';
    }
}
