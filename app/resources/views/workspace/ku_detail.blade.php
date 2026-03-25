@extends('layouts.app')
@section('title', $ku->topic . ' — KPS')

@section('extra-styles')
        .field { margin-bottom: 16px; }
        .field label { display: block; font-size: 12px; font-weight: 500; color: #5f6368; margin-bottom: 4px; text-transform: uppercase; letter-spacing: 0.5px; }
        textarea, input[type="text"] { width: 100%; padding: 8px 12px; border: 1px solid #d2d2d7; border-radius: 8px; font-size: 14px; font-family: inherit; }
        textarea { min-height: 80px; resize: vertical; }
        .keywords { display: flex; gap: 6px; flex-wrap: wrap; }
        .keyword { background: #f0f0f2; padding: 2px 8px; border-radius: 6px; font-size: 12px; }
        .actions { display: flex; gap: 8px; }
@endsection

@section('body')
    <div class="page-content">
        <div class="page-container" style="max-width: 800px;">
            <div style="margin-bottom: 16px;">
                <a href="{{ route('workspace.embedding', ['embeddingId' => $current->id]) }}" style="font-size: 13px; color: #0071e3; text-decoration: none;">← {{ $current->name }}</a>
            </div>

            @if(session('success'))
                <p style="color: #34c759; font-size: 13px; margin-bottom: 16px;">✓ {{ session('success') }}</p>
            @endif

            <!-- KU Header -->
            <div class="card">
                <div style="display: flex; justify-content: space-between; align-items: start;">
                    <div>
                        <h2 style="margin-bottom: 8px;">{{ $ku->topic }}</h2>
                        <span class="badge badge-{{ $ku->review_status }}">{{ $ku->review_status }}</span>
                        <span style="font-size: 12px; color: #5f6368; margin-left: 8px;">{{ $ku->row_count }} rows · {{ $ku->language ?? 'en' }}</span>
                    </div>
                    <div class="actions">
                        @if($ku->review_status === 'draft')
                            <form method="POST" action="{{ route('knowledge-units.review', $ku) }}">
                                @csrf
                                <input type="hidden" name="status" value="approved">
                                <button type="submit" class="btn btn-success">Approve</button>
                            </form>
                        @endif
                        @if($ku->review_status !== 'rejected')
                            <form method="POST" action="{{ route('knowledge-units.review', $ku) }}">
                                @csrf
                                <input type="hidden" name="status" value="rejected">
                                <button type="submit" class="btn btn-danger">Reject</button>
                            </form>
                        @endif
                    </div>
                </div>
            </div>

            <!-- Editable Fields -->
            <div class="card">
                <h2>Details</h2>
                <form method="POST" action="{{ route('knowledge-units.update', $ku) }}">
                    @csrf
                    @method('PUT')
                    <div class="field">
                        <label>{{ __('ui.topic') }}</label>
                        <input type="text" name="topic" value="{{ $ku->topic }}">
                    </div>
                    <div class="field">
                        <label>{{ __('ui.intent') }}</label>
                        <input type="text" name="intent" value="{{ $ku->intent }}">
                    </div>
                    <div class="field">
                        <label>Question</label>
                        <textarea name="question">{{ $ku->question }}</textarea>
                    </div>
                    <div class="field">
                        <label>{{ __('ui.summary') }}</label>
                        <textarea name="summary">{{ $ku->summary }}</textarea>
                    </div>
                    <div class="field">
                        <label>Symptoms</label>
                        <textarea name="symptoms">{{ $ku->symptoms }}</textarea>
                    </div>
                    <div class="field">
                        <label>Root Cause</label>
                        <textarea name="root_cause">{{ $ku->root_cause }}</textarea>
                    </div>
                    <div class="field">
                        <label>Resolution Summary</label>
                        <textarea name="resolution_summary">{{ $ku->resolution_summary }}</textarea>
                    </div>
                    <div class="field">
                        <label>Cause Summary</label>
                        <textarea name="cause_summary">{{ $ku->cause_summary }}</textarea>
                    </div>
                    <div style="display: flex; gap: 16px;">
                        <div class="field" style="flex: 1;">
                            <label>Product</label>
                            <input type="text" name="product" value="{{ $ku->product }}">
                        </div>
                        <div class="field" style="flex: 1;">
                            <label>Category</label>
                            <input type="text" name="category" value="{{ $ku->category }}">
                        </div>
                    </div>
                    <div class="field">
                        <label>Notes</label>
                        <textarea name="notes">{{ $ku->notes }}</textarea>
                    </div>
                    <div class="field">
                        <label>Edit Comment</label>
                        <input type="text" name="edit_comment" placeholder="Describe your changes...">
                    </div>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </form>
            </div>

            <!-- Keywords -->
            <div class="card">
                <h2>{{ __('ui.keywords') }}</h2>
                <div class="keywords">
                    @foreach($ku->keywords_json ?? [] as $kw)
                        <span class="keyword">{{ $kw }}</span>
                    @endforeach
                    @if(empty($ku->keywords_json))
                        <span style="color: #5f6368; font-size: 13px;">No keywords</span>
                    @endif
                </div>
            </div>

            @if($ku->representative_rows_json)
            <div class="card">
                <h2>{{ __('ui.representative_rows') }}</h2>
                @foreach($ku->representative_rows_json as $i => $row)
                    <div style="padding: 8px 0; border-bottom: 1px solid #f0f0f2; font-size: 13px; line-height: 1.5;">
                        <span style="color: #5f6368; font-size: 11px;">#{{ $i + 1 }}</span>
                        {{ Str::limit(is_array($row) ? json_encode($row, JSON_UNESCAPED_UNICODE) : (string) $row, 200) }}
                    </div>
                @endforeach
            </div>
            @endif

            <div style="margin-top: 16px;">
                <a href="{{ route('knowledge-units.versions', $ku) }}" class="btn btn-outline">Version History</a>
            </div>
        </div>
    </div>
@endsection
