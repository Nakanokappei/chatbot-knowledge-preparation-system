{{-- Document library: upload source PDFs and see what has been stored.
     Files are kept as-is; no extraction or embedding happens yet. --}}
@extends('layouts.app')
@section('title', __('ui.documents') . ' — KPS')

@section('extra-styles')
        .documents-header { margin-bottom: 24px; }
        .documents-header h1 { font-size: 20px; font-weight: 600; margin-bottom: 4px; }
        .documents-header p { font-size: 13px; color: #5f6368; }

        .upload-card { background: #fff; border: 1px solid #e5e5e7; border-radius: 12px; padding: 20px; margin-bottom: 24px; }
        .upload-card h2 { font-size: 15px; font-weight: 600; margin-bottom: 12px; }
        .upload-row { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
        .upload-hint { font-size: 12px; color: #86868b; margin-top: 10px; }

        .flash { padding: 10px 14px; border-radius: 8px; margin-bottom: 16px; font-size: 13px; }
        .flash-success { background: #d4edda; color: #155724; }
        .flash-error { background: #f8d7da; color: #721c24; }

        .doc-table { width: 100%; border-collapse: collapse; background: #fff; border: 1px solid #e5e5e7; border-radius: 12px; overflow: hidden; }
        .doc-table th { text-align: left; font-size: 12px; font-weight: 600; color: #5f6368; padding: 10px 16px; background: #fafafa; border-bottom: 1px solid #e5e5e7; }
        .doc-table td { font-size: 13px; padding: 12px 16px; border-bottom: 1px solid #f0f0f2; vertical-align: middle; }
        .doc-table tr:last-child td { border-bottom: none; }
        .doc-name { display: flex; align-items: center; gap: 8px; color: #1d1d1f; word-break: break-all; }
        .doc-icon { flex-shrink: 0; color: #ff3b30; }
        .doc-actions { text-align: right; white-space: nowrap; }

        .empty-state { background: #fff; border: 1px solid #e5e5e7; border-radius: 12px; padding: 48px 24px; text-align: center; color: #5f6368; }
        .empty-state .empty-title { font-size: 16px; font-weight: 600; color: #1d1d1f; margin-bottom: 6px; }
        .empty-state p { font-size: 13px; }
@endsection

@section('body')
<div class="page-content">
    <div class="page-container">

        <div class="documents-header">
            <h1>{{ __('ui.documents') }}</h1>
            <p>{{ __('ui.documents_subtitle') }}</p>
        </div>

        @if(session('success'))
            <div class="flash flash-success">✓ {{ session('success') }}</div>
        @endif
        @if(session('error'))
            <div class="flash flash-error">✗ {{ session('error') }}</div>
        @endif
        @if($errors->any())
            <div class="flash flash-error">✗ {{ $errors->first() }}</div>
        @endif

        {{-- Upload form: multiple PDFs in one submission --}}
        <div class="upload-card">
            <h2>{{ __('ui.documents_upload') }}</h2>
            <form method="POST" action="{{ route('documents.store') }}" enctype="multipart/form-data"
                  id="document-upload-form" onsubmit="return checkTotalSize(event);">
                @csrf
                <div class="upload-row">
                    <input type="file" id="document-input" name="documents[]" accept="application/pdf,.pdf" multiple required
                           style="font-size: 13px; flex: 1; min-width: 240px;">
                    <button type="submit" class="btn btn-primary">{{ __('ui.documents_upload_button') }}</button>
                </div>
                <div class="upload-hint">
                    {{ __('ui.documents_upload_hint', ['files' => $maxFiles, 'size' => $maxMegabytes]) }}
                </div>
            </form>
        </div>

        {{-- Stored documents, newest first --}}
        @if($documents->isEmpty())
            <div class="empty-state">
                <div class="empty-title">{{ __('ui.documents_empty_title') }}</div>
                <p>{{ __('ui.documents_empty_hint') }}</p>
            </div>
        @else
            <table class="doc-table">
                <thead>
                    <tr>
                        <th>{{ __('ui.documents_col_name') }}</th>
                        <th style="width: 100px;">{{ __('ui.documents_col_size') }}</th>
                        <th style="width: 140px;">{{ __('ui.documents_col_uploader') }}</th>
                        <th style="width: 160px;">{{ __('ui.documents_col_uploaded_at') }}</th>
                        <th style="width: 160px;"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($documents as $document)
                        <tr>
                            <td>
                                <div class="doc-name">
                                    <svg class="doc-icon" width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.3">
                                        <path d="M9 1.5H4a1 1 0 00-1 1v11a1 1 0 001 1h8a1 1 0 001-1V5.5L9 1.5z"/>
                                        <path d="M9 1.5v4h4"/>
                                    </svg>
                                    {{ $document->original_filename }}
                                </div>
                            </td>
                            <td style="color: #5f6368;">{{ $document->formattedSize() }}</td>
                            <td style="color: #5f6368;">{{ $document->uploader?->name ?? '—' }}</td>
                            <td style="color: #5f6368;">
                                <time datetime="{{ $document->created_at->toIso8601String() }}" data-format="full">{{ $document->created_at->format('Y/m/d H:i') }}</time>
                            </td>
                            <td class="doc-actions">
                                <a href="{{ route('documents.download', $document) }}" class="btn btn-outline btn-sm">{{ __('ui.documents_download') }}</a>
                                <form method="POST" action="{{ route('documents.destroy', $document) }}" style="display: inline;"
                                      onsubmit="return confirm('{{ __('ui.documents_delete_confirm') }}');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-danger btn-sm">{{ __('ui.delete') }}</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

    </div>
</div>
@endsection

@section('scripts')
    // Block submission when the combined size exceeds what PHP will accept.
    // Past post_max_size the request body is discarded before Laravel runs,
    // which would surface as a CSRF error instead of a useful message.
    function checkTotalSize(event) {
        const input = document.getElementById('document-input');
        const maxTotalBytes = {{ $maxTotalBytes }};

        let totalBytes = 0;
        for (const file of input.files) {
            totalBytes += file.size;
        }

        if (totalBytes > maxTotalBytes) {
            event.preventDefault();
            alert(@json(__('ui.documents_upload_too_large', ['size' => $maxMegabytes])));
            return false;
        }

        return true;
    }
@endsection
