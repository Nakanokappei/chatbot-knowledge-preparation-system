{{-- Dataset configuration overlay: full-screen modal for configuring a CSV dataset before
     running the pipeline. Includes basic settings (name, header row, encoding), LLM-generated
     descriptions, drag-and-drop column mapper for embedding, knowledge structure mapping
     (question, symptoms, root cause, resolution, product, category), embedding preview,
     and pipeline settings (clustering method, model selection). --}}
@extends('layouts.app')
@section('title', "Configure Dataset — {$dataset->name}")

@section('extra-styles')
        /* Full-screen overlay like Gmail compose maximized */
        .configure-overlay { position: fixed; inset: 0; z-index: 50; display: flex; align-items: center; justify-content: center; background: rgba(0,0,0,0.3); }
        .configure-panel { background: #fff; border-radius: 12px; width: 90vw; max-width: 1100px; max-height: 90vh; display: flex; flex-direction: column; box-shadow: 0 8px 32px rgba(0,0,0,0.2); overflow: hidden; }
        .configure-titlebar { display: flex; align-items: center; justify-content: space-between; padding: 12px 20px; background: #F3F3F3; border-radius: 12px 12px 0 0; flex-shrink: 0; }
        .configure-titlebar h1 { font-size: 16px; font-weight: 400; margin: 0; }
        .configure-titlebar .close-btn { background: none; border: none; font-size: 20px; color: #5f6368; cursor: pointer; padding: 4px 8px; border-radius: 4px; text-decoration: none; }
        .configure-titlebar .close-btn:hover { background: #E9E9E9; }
        .configure-body { flex: 1; overflow-y: auto; padding: 24px; }
        .page-content { max-width: 100%; margin: 0; }
        .card { background: #fff; border-radius: 12px; padding: 20px; margin-bottom: 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.08); }
        .card h2 { font-size: 15px; font-weight: 600; margin-bottom: 12px; }
        .row { display: flex; gap: 16px; }
        .col { flex: 1; }
        label { font-size: 12px; color: #5f6368; display: block; margin-bottom: 4px; }
        select, input[type=text] { padding: 8px 12px; border: 1px solid #d2d2d7; border-radius: 8px; font-size: 14px; width: 100%; }
        .btn-success { background: #30d158; color: #fff; }
        .btn-success:hover { background: #28b84c; }

        /* Column mapper */
        .mapper { display: flex; gap: 16px; min-height: 300px; }
        .mapper-panel { flex: 1; border: 1px solid #e5e5e7; border-radius: 10px; padding: 12px; background: #fafafa; display: flex; flex-direction: column; }
        .mapper-panel h3 { font-size: 13px; font-weight: 600; margin-bottom: 8px; color: #5f6368; flex-shrink: 0; }
        .mapper-panel.selected { background: #f0f8ff; border-color: #0071e3; }
        .col-item {
            display: flex; align-items: center; gap: 8px; padding: 8px 12px;
            background: #fff; border: 1px solid #e5e5e7; border-radius: 8px;
            margin-bottom: 6px; cursor: grab; font-size: 13px; transition: all 0.15s;
        }
        .col-item:hover { border-color: #0071e3; background: #f0f8ff; }
        .col-item.dragging { opacity: 0.5; }
        .col-item .col-index { font-size: 11px; color: #5f6368; min-width: 20px; }
        .col-item .col-name { flex: 1; font-weight: 500; }
        .col-item .col-label-input { width: 120px; padding: 4px 8px; border: 1px solid #d2d2d7; border-radius: 6px; font-size: 12px; }
        .col-item .remove-btn { background: none; border: none; color: #ff3b30; cursor: pointer; font-size: 16px; padding: 0 4px; }
        .col-item .add-btn { background: none; border: none; color: #0071e3; cursor: pointer; font-size: 18px; padding: 0 4px; }
        .col-item .handle { cursor: grab; color: #c7c7cc; font-size: 14px; }
        .drop-zone { flex: 1; min-height: 60px; border: 2px dashed #d2d2d7; border-radius: 8px; padding: 16px; text-align: center; color: #5f6368; font-size: 13px; transition: all 0.15s; display: flex; align-items: center; justify-content: center; }
        .drop-zone.over { border-color: #0071e3; background: #f0f8ff; }
        .insert-indicator { height: 3px; background: #0071e3; border-radius: 2px; margin: -1px 0; transition: opacity 0.1s; pointer-events: none; }
        #available-list .col-item { cursor: grab; }
        #available-list .col-item[draggable=true]:active { cursor: grabbing; }

        /* Preview */
        .preview-box { background: #1d1d1f; color: #e5e5e7; border-radius: 10px; padding: 16px; font-family: 'SF Mono', 'Menlo', monospace; font-size: 12px; line-height: 1.6; max-height: 400px; overflow-y: auto; white-space: pre-wrap; }
        .preview-row { margin-bottom: 12px; padding-bottom: 12px; border-bottom: 1px solid #333; }
        .preview-row:last-child { border-bottom: none; margin-bottom: 0; padding-bottom: 0; }
        .preview-label { color: #64d2ff; }
        .preview-value { color: #fff; }

        .pipeline-options { display: flex; gap: 12px; align-items: flex-end; flex-wrap: wrap; margin-top: 12px; }
@endsection

@section('body')
    <div class="configure-overlay" onclick="if(event.target===this) window.location='{{ route('workspace.index') }}'">
        <div class="configure-panel">
            <div class="configure-titlebar">
                <h1>{{ __('ui.new_dataset') ?? 'New Dataset' }}: {{ $dataset->name }}</h1>
                <a href="{{ route('workspace.index') }}" class="close-btn" title="Close">&times;</a>
            </div>
            <div class="configure-body">
                <p style="color: #5f6368; font-size: 13px; margin-bottom: 24px;">{{ $dataset->original_filename }} — {{ $totalLines }} data rows — Encoding: {{ $detectedEncoding }}</p>

                @if(session('error'))
                    <div style="color: #ff3b30; font-size: 13px; margin-bottom: 12px;">✗ {{ session('error') }}</div>
                @endif

                <form id="config-form" method="POST" action="{{ route('dataset.finalize', $dataset) }}">
                    @csrf

            {{-- Basic settings: dataset name, header row toggle, and character encoding --}}
            <div class="card">
                <h2>{{ __('ui.basic_settings') }}</h2>
                <div class="row">
                    <div class="col">
                        <label>{{ __('ui.dataset_name') }}</label>
                        <input type="text" name="dataset_name" value="{{ $dataset->name }}">
                    </div>
                    <div class="col">
                        <label>{{ __('ui.first_row_is') }}</label>
                        <select id="has-header" name="has_header" onchange="refreshPreview()">
                            <option value="1" selected>{{ __('ui.header_option') }}</option>
                            <option value="0">{{ __('ui.data_option') }}</option>
                        </select>
                    </div>
                    <div class="col">
                        <label>{{ __('ui.encoding') }}</label>
                        <select id="encoding" name="encoding" onchange="reEncodeDataset(this.value)">
                            <option value="UTF-8" {{ $detectedEncoding === 'UTF-8' ? 'selected' : '' }}>UTF-8</option>
                            <option value="Shift_JIS" {{ $detectedEncoding === 'Shift_JIS' ? 'selected' : '' }}>Shift_JIS</option>
                            <option value="EUC-JP" {{ $detectedEncoding === 'EUC-JP' ? 'selected' : '' }}>EUC-JP</option>
                            <option value="ISO-8859-1" {{ $detectedEncoding === 'ISO-8859-1' ? 'selected' : '' }}>ISO-8859-1 (Latin)</option>
                        </select>
                    </div>
                </div>
            </div>

            {{-- Descriptions: LLM-generated dataset and per-column descriptions (editable) --}}
            <div class="card">
                <h2>Descriptions</h2>
                <p style="font-size: 12px; color: #5f6368; margin-bottom: 12px;">
                    Auto-generated by LLM from sample rows. Edit if needed — these descriptions help the LLM understand your data during analysis.
                </p>
                <div style="margin-bottom: 16px;">
                    <label style="font-weight: 500; font-size: 13px;">Dataset Description</label>
                    <textarea name="dataset_description" rows="2"
                        style="width: 100%; padding: 8px 10px; border: 1px solid #d2d2d7; border-radius: 6px; font-size: 13px; resize: vertical;"
                        placeholder="Describe what this dataset contains...">{{ $dataset->schema_json['dataset_description'] ?? '' }}</textarea>
                </div>
                <div>
                    <label style="font-weight: 500; font-size: 13px; margin-bottom: 8px; display: block;">Column Descriptions</label>
                    <div style="display: grid; grid-template-columns: 160px 1fr; gap: 6px 12px; align-items: center;">
                        @php $colDescs = $dataset->schema_json['column_descriptions'] ?? []; @endphp
                        @foreach($columns as $col)
                            <label style="font-size: 12px; color: #1d1d1f; font-weight: 500; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="{{ $col }}">{{ $col }}</label>
                            <input type="text" name="column_descriptions[{{ $col }}]"
                                value="{{ $colDescs[$col] ?? '' }}"
                                style="padding: 5px 8px; border: 1px solid #d2d2d7; border-radius: 6px; font-size: 12px;"
                                placeholder="Description...">
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- Column mapper: drag-and-drop interface to select and order columns for embedding --}}
            <div class="card">
                <h2>Clustering Columns</h2>
                <p style="font-size: 12px; color: #5f6368; margin-bottom: 12px;">
                    Select columns to embed and cluster. These columns determine how rows are grouped into topics.
                    Click <strong>+</strong> to add, drag to reorder. The label prefix appears before each value in the embedding text.
                </p>
                <div class="mapper">
                    <div class="mapper-panel" id="available-panel">
                        <h3>{{ __('ui.available_columns') }}</h3>
                        <div id="available-list">
                            @foreach($columns as $columnIndex => $columnName)
                            <div class="col-item" data-index="{{ $columnIndex }}">
                                <span class="col-index">#{{ $columnIndex + 1 }}</span>
                                <span class="col-name">{{ $columnName }}</span>
                                <span style="font-size: 11px; color: #5f6368; max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                    {{ isset($previewRows[0][$columnIndex]) ? Str::limit($previewRows[0][$columnIndex], 40) : '' }}
                                </span>
                                <button type="button" class="add-btn" onclick="addColumn({{ $columnIndex }}, '{{ addslashes($columnName) }}')" title="Add to embedding">+</button>
                            </div>
                            @endforeach
                        </div>
                    </div>
                    <div class="mapper-panel selected" id="selected-panel">
                        <h3>{{ __('ui.embedding_columns') }}</h3>
                        <div id="selected-list"></div>
                        <div class="drop-zone" id="drop-zone">{{ __('ui.drop_here') }}</div>
                    </div>
                </div>
            </div>

            {{-- Embedding preview: dark terminal-style box showing how selected columns will be combined --}}
            <div class="card">
                <h2>{{ __('ui.embedding_preview') }}</h2>
                <div class="preview-box" id="preview-box">
                    <span style="color: #5f6368;">Select columns above to see preview...</span>
                </div>
            </div>

            {{-- Knowledge structure mapping: map CSV columns to KU fields or let LLM generate them --}}
            <div class="card">
                <h2>Knowledge Structure</h2>
                <p style="font-size: 12px; color: #5f6368; margin-bottom: 16px;">
                    Map CSV columns to knowledge fields. For each field, choose a source column or let the LLM generate/extract it from representative data.
                    The <strong>Question</strong> field is used for chatbot similarity search — it should represent what a user would ask.
                </p>

                <div style="display: grid; grid-template-columns: 160px 1fr; gap: 10px; align-items: center;">
                    {{-- Question --}}
                    <label style="font-weight: 500; font-size: 13px;">Question</label>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <select name="km_question_source" id="km-question-source" onchange="toggleKmLlm('question')"
                            style="padding: 6px 10px; border: 1px solid #d2d2d7; border-radius: 6px; font-size: 13px; width: 220px;">
                            <option value="_llm">Generate with LLM</option>
                            @foreach($columns as $columnIndex => $columnName)
                                <option value="{{ $columnIndex }}">{{ $columnName }}</option>
                            @endforeach
                        </select>
                        <span id="km-question-hint" style="font-size: 11px; color: #5f6368;">LLM generates a FAQ-style question from representative rows</span>
                    </div>

                    {{-- Symptoms --}}
                    <label style="font-weight: 500; font-size: 13px;">Symptoms</label>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <select name="km_symptoms_source" id="km-symptoms-source" onchange="toggleKmLlm('symptoms')"
                            style="padding: 6px 10px; border: 1px solid #d2d2d7; border-radius: 6px; font-size: 13px; width: 220px;">
                            <option value="_llm">Extract with LLM</option>
                            @foreach($columns as $columnIndex => $columnName)
                                <option value="{{ $columnIndex }}">{{ $columnName }}</option>
                            @endforeach
                        </select>
                        <span id="km-symptoms-hint" style="font-size: 11px; color: #5f6368;">Error messages, surface-level phenomena from user reports</span>
                    </div>

                    {{-- Root Cause --}}
                    <label style="font-weight: 500; font-size: 13px;">Root Cause</label>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <select name="km_root_cause_source" id="km-root_cause-source" onchange="toggleKmLlm('root_cause')"
                            style="padding: 6px 10px; border: 1px solid #d2d2d7; border-radius: 6px; font-size: 13px; width: 220px;">
                            <option value="_llm">Extract with LLM</option>
                            @foreach($columns as $columnIndex => $columnName)
                                <option value="{{ $columnIndex }}">{{ $columnName }}</option>
                            @endforeach
                        </select>
                        <span id="km-root_cause-hint" style="font-size: 11px; color: #5f6368;">Underlying technical cause extracted from resolution data</span>
                    </div>

                    {{-- Resolution --}}
                    <label style="font-weight: 500; font-size: 13px;">Resolution</label>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <select name="km_resolution_source" id="km-resolution-source" onchange="toggleKmLlm('resolution')"
                            style="padding: 6px 10px; border: 1px solid #d2d2d7; border-radius: 6px; font-size: 13px; width: 220px;">
                            <option value="_llm">Generate with LLM</option>
                            @foreach($columns as $columnIndex => $columnName)
                                <option value="{{ $columnIndex }}">{{ $columnName }}</option>
                            @endforeach
                        </select>
                        <span id="km-resolution-hint" style="font-size: 11px; color: #5f6368;">How to resolve the issue — maps to resolution_summary</span>
                    </div>

                    {{-- Primary Filter (required) --}}
                    <label style="font-weight: 500; font-size: 13px;">Primary Filter <sup style="color: #d93025; font-size: 9px; font-weight: normal;">required</sup></label>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <select name="km_product_source" id="km-product-source" onchange="toggleKmLlm('product')" required
                            style="padding: 6px 10px; border: 1px solid #d2d2d7; border-radius: 6px; font-size: 13px; width: 220px;">
                            <option value="_llm">Extract with LLM</option>
                            @foreach($columns as $columnIndex => $columnName)
                                <option value="{{ $columnIndex }}">{{ $columnName }}</option>
                            @endforeach
                        </select>
                        <span id="km-product-hint" style="font-size: 11px; color: #5f6368;">Key attribute for chat filtering (e.g. product, region, department)</span>
                    </div>

                    {{-- Category --}}
                    <label style="font-weight: 500; font-size: 13px;">Category</label>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <select name="km_category_source" id="km-category-source" onchange="toggleKmLlm('category')"
                            style="padding: 6px 10px; border: 1px solid #d2d2d7; border-radius: 6px; font-size: 13px; width: 220px;">
                            <option value="_none">Not used</option>
                            <option value="_llm">Generate with LLM</option>
                            @foreach($columns as $columnIndex => $columnName)
                                <option value="{{ $columnIndex }}">{{ $columnName }}</option>
                            @endforeach
                        </select>
                        <span id="km-category-hint" style="font-size: 11px; color: #5f6368;">Classification tag for organizing knowledge</span>
                    </div>

                </div>

                {{-- LLM fallback option — outside the grid so it spans full width --}}
                <div style="margin-top: 16px; padding-top: 12px; border-top: 1px solid #e5e5e7;">
                    <label style="display: flex; align-items: center; gap: 8px; font-size: 13px; cursor: pointer;">
                        <input type="hidden" name="llm_fallback" value="0">
                        <input type="checkbox" name="llm_fallback" value="1" checked
                            style="width: 16px; height: 16px; flex-shrink: 0; accent-color: #0071e3;">
                        <span style="font-weight: 500; white-space: nowrap;">LLM Fallback</span>
                        <span style="color: #5f6368; font-weight: 400;">— When a mapped column has empty or low-quality data, automatically use LLM to generate the value</span>
                    </label>
                </div>
            </div>

            {{-- Pipeline settings: embedding model, LLM model, clustering method, and run buttons --}}
            <div class="card">
                <h2>{{ __('ui.pipeline_settings') }}</h2>
                <div class="pipeline-options">
                    <div>
                        <label>{{ __('ui.embedding_model') ?? 'Embedding Model' }}</label>
                        <select name="embedding_model_id" style="padding: 8px 12px; border: 1px solid #d2d2d7; border-radius: 8px; font-size: 14px;">
                            @foreach($embeddingModels as $em)
                                <option value="{{ $em->model_id }}" @if($em->is_default) selected @endif>
                                    {{ $em->display_name }} ({{ $em->dimension }}d)
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label>{{ __('ui.llm_model') }}</label>
                        <select name="llm_model_id" style="padding: 8px 12px; border: 1px solid #d2d2d7; border-radius: 8px; font-size: 14px;">
                            @foreach($llmModels as $model)
                                <option value="{{ $model->model_id }}" @if($model->is_default) selected @endif>{{ $model->display_name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label>{{ __('ui.clustering_method') }}</label>
                        <select name="clustering_method" id="clustering-method" style="padding: 8px 12px; border: 1px solid #d2d2d7; border-radius: 8px; font-size: 14px;">
                            <option value="hdbscan" selected>HDBSCAN (density-based, auto)</option>
                            <option value="kmeans">K-Means++ (spherical)</option>
                            <option value="agglomerative">Agglomerative (hierarchical)</option>
                            <option value="leiden">HNSW + Leiden (graph community)</option>
                        </select>
                    </div>
                </div>
                <!-- Clustering parameters -->
                <div id="clustering-params" style="margin-top: 8px; display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
                    <div id="params-hdbscan">
                        <label style="display: inline;">min_cluster_size</label>
                        <input type="number" name="hdbscan_min_cluster_size" value="15" min="2" max="500" style="width: 70px; padding: 6px 8px; border: 1px solid #d2d2d7; border-radius: 6px; font-size: 13px;">
                        <label style="display: inline; margin-left: 8px;">min_samples</label>
                        <input type="number" name="hdbscan_min_samples" value="5" min="1" max="100" style="width: 60px; padding: 6px 8px; border: 1px solid #d2d2d7; border-radius: 6px; font-size: 13px;">
                    </div>
                    <div id="params-kmeans" style="display: none;">
                        <label style="display: inline;">n_clusters</label>
                        <input type="number" name="kmeans_n_clusters" value="10" min="2" max="200" style="width: 70px; padding: 6px 8px; border: 1px solid #d2d2d7; border-radius: 6px; font-size: 13px;">
                    </div>
                    <div id="params-agglomerative" style="display: none;">
                        <label style="display: inline;">n_clusters</label>
                        <input type="number" name="agglomerative_n_clusters" value="10" min="2" max="200" style="width: 70px; padding: 6px 8px; border: 1px solid #d2d2d7; border-radius: 6px; font-size: 13px;">
                        <label style="display: inline; margin-left: 8px;">linkage</label>
                        <select name="agglomerative_linkage" style="padding: 6px 8px; border: 1px solid #d2d2d7; border-radius: 6px; font-size: 13px;">
                            <option value="ward">ward</option><option value="complete">complete</option>
                            <option value="average">average</option><option value="single">single</option>
                        </select>
                    </div>
                    <div id="params-leiden" style="display: none;">
                        <label style="display: inline;">n_neighbors</label>
                        <input type="number" name="leiden_n_neighbors" value="15" min="5" max="100" style="width: 70px; padding: 6px 8px; border: 1px solid #d2d2d7; border-radius: 6px; font-size: 13px;">
                        <label style="display: inline; margin-left: 8px;">resolution</label>
                        <input type="number" name="leiden_resolution" value="1.0" min="0.1" max="10.0" step="0.1" style="width: 70px; padding: 6px 8px; border: 1px solid #d2d2d7; border-radius: 6px; font-size: 13px;">
                    </div>
                </div>
                <div style="margin-top: 12px;">
                    <label style="display: flex; align-items: center; gap: 8px; font-size: 13px; color: #1d1d1f; cursor: pointer;">
                        <input type="checkbox" name="remove_language_bias" value="1" checked
                            style="width: 16px; height: 16px; accent-color: #0071e3;">
                        {{ __('ui.remove_language_bias') }}
                    </label>
                </div>
                <div style="margin-top: 16px; display: flex; gap: 12px; align-items: center; flex-wrap: nowrap;">
                    <button type="submit" class="btn btn-success" id="start-btn" disabled style="padding: 10px 24px; font-size: 14px; white-space: nowrap;">
                        {{ __('ui.start_pipeline') }}
                    </button>
                    <button type="submit" name="test_mode" value="500" class="btn" id="test-btn" disabled
                        style="padding: 10px 24px; font-size: 14px; background: #ff9500; color: #fff; border: none; border-radius: 8px; cursor: pointer; white-space: nowrap;">
                        🧪 {{ __('ui.test_pipeline') ?? 'Test (max 500 rows)' }}
                    </button>
                    <span id="col-count-msg" style="font-size: 13px; color: #5f6368; white-space: nowrap;">{{ __('ui.select_one_column') }}</span>
                </div>
            </div>

                    <div id="hidden-inputs"></div>
                </form>
            </div>
        </div>
    </div>
@endsection

@section('scripts')
        let allColumns = @json($columns);
        let previewRows = @json($previewRows);
        let selectedColumns = [];
        let dragSource = null;  // 'available' or 'selected'
        let dragData = null;    // column index (available) or position (selected)

        // Re-encode the CSV file with a different character encoding and rebuild all column-dependent UI
        async function reEncodeDataset(encoding) {
            const token = document.querySelector('meta[name="csrf-token"]')?.content
                || document.querySelector('input[name="_token"]')?.value;
            try {
                const res = await fetch('{{ route("dataset.re-encode", $dataset) }}', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token },
                    body: JSON.stringify({ encoding }),
                });
                const data = await res.json();
                if (data.error) { alert(data.error); return; }

                // Update global state
                allColumns = data.columns;
                previewRows = data.previewRows;

                // Rebuild available columns list
                const avail = document.getElementById('available-list');
                avail.innerHTML = '';
                data.columns.forEach((col, i) => {
                    const preview = (data.previewRows[0] && data.previewRows[0][i])
                        ? data.previewRows[0][i].substring(0, 40) : '';
                    const div = document.createElement('div');
                    div.className = 'col-item';
                    div.dataset.index = i;
                    div.innerHTML = `<span class="col-index">#${i+1}</span>`
                        + `<span class="col-name">${col}</span>`
                        + `<span style="font-size:11px;color:#5f6368;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${preview}</span>`
                        + `<button type="button" class="add-btn" onclick="addColumn(${i},'${col.replace(/'/g,"\\'")}')">+</button>`;
                    avail.appendChild(div);
                });

                // Clear selected columns (mappings are now invalid with new column names)
                selectedColumns = [];
                renderSelected();

                // Update column descriptions section
                const descGrid = document.querySelector('[name^="column_descriptions"]')?.closest('div[style*="grid"]');
                if (descGrid) {
                    descGrid.innerHTML = data.columns.map(col =>
                        `<label style="font-size:12px;color:#1d1d1f;font-weight:500;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${col}">${col}</label>`
                        + `<input type="text" name="column_descriptions[${col}]" value="" style="padding:5px 8px;border:1px solid #d2d2d7;border-radius:6px;font-size:12px;" placeholder="Description...">`
                    ).join('');
                }

                // Update knowledge mapping dropdowns
                document.querySelectorAll('select[name^="km_"]').forEach(sel => {
                    // Keep first option(s) that aren't column indices
                    const staticOptions = [...sel.options].filter(o => !o.value.match(/^\d+$/));
                    sel.innerHTML = '';
                    staticOptions.forEach(o => sel.appendChild(o));
                    data.columns.forEach((col, i) => {
                        const opt = document.createElement('option');
                        opt.value = i;
                        opt.textContent = col;
                        sel.appendChild(opt);
                    });
                });

            } catch (err) {
                alert('Re-encode failed: ' + err.message);
            }
        }

        // Add a column to the selected embedding columns list at the specified position
        function addColumn(index, name, insertAt) {
            if (selectedColumns.find(c => c.index === index)) return;
            const col = { index: index, label: name };
            if (insertAt !== undefined && insertAt >= 0) {
                selectedColumns.splice(insertAt, 0, col);
            } else {
                selectedColumns.push(col);
            }
            renderSelected();
            refreshPreview();
        }

        function removeColumn(index) {
            selectedColumns = selectedColumns.filter(c => c.index !== index);
            renderSelected();
            refreshPreview();
        }

        function updateLabel(index, newLabel) {
            const col = selectedColumns.find(c => c.index === index);
            if (col) col.label = newLabel;
            refreshPreview();
        }

        function moveColumn(fromIdx, toIdx) {
            if (toIdx < 0 || toIdx >= selectedColumns.length) return;
            if (fromIdx === toIdx) return;
            const item = selectedColumns.splice(fromIdx, 1)[0];
            selectedColumns.splice(toIdx > fromIdx ? toIdx - 1 : toIdx, 0, item);
            renderSelected();
            refreshPreview();
        }

        function clearInsertIndicators() {
            document.querySelectorAll('.insert-indicator').forEach(el => el.remove());
        }

        function showInsertIndicator(targetEl, position) {
            clearInsertIndicators();
            const indicator = document.createElement('div');
            indicator.className = 'insert-indicator';
            if (position === 'before') {
                targetEl.parentNode.insertBefore(indicator, targetEl);
            } else {
                targetEl.parentNode.insertBefore(indicator, targetEl.nextSibling);
            }
        }

        function getDropPosition(e, el) {
            const rect = el.getBoundingClientRect();
            return (e.clientY - rect.top) < rect.height / 2 ? 'before' : 'after';
        }

        // Make available items draggable to selected panel
        function setupAvailableDrag() {
            document.querySelectorAll('#available-list .col-item').forEach(item => {
                item.draggable = true;
                item.addEventListener('dragstart', (e) => {
                    dragSource = 'available';
                    dragData = parseInt(item.dataset.index);
                    e.dataTransfer.effectAllowed = 'copy';
                    item.classList.add('dragging');
                });
                item.addEventListener('dragend', () => {
                    item.classList.remove('dragging');
                    clearInsertIndicators();
                    dragSource = null;
                    dragData = null;
                });
            });
        }

        // Render the selected columns panel with drag handles, label inputs, and hidden form fields
        function renderSelected() {
            const list = document.getElementById('selected-list');
            const dropZone = document.getElementById('drop-zone');
            const startBtn = document.getElementById('start-btn');
            const colCountMsg = document.getElementById('col-count-msg');
            const hiddenInputs = document.getElementById('hidden-inputs');

            list.innerHTML = '';
            hiddenInputs.innerHTML = '';

            selectedColumns.forEach((col, pos) => {
                const div = document.createElement('div');
                div.className = 'col-item';
                div.draggable = true;
                div.dataset.pos = pos;
                div.innerHTML = `
                    <span class="handle" title="Drag to reorder">⠿</span>
                    <span class="col-index">#${col.index + 1}</span>
                    <span class="col-name">${allColumns[col.index]}</span>
                    <input type="text" class="col-label-input" value="${col.label}"
                        onchange="updateLabel(${col.index}, this.value)" placeholder="Label">
                    <button type="button" class="remove-btn" onclick="removeColumn(${col.index})" title="Remove">×</button>
                `;

                // Drag from selected (reorder)
                div.addEventListener('dragstart', (e) => {
                    dragSource = 'selected';
                    dragData = pos;
                    e.dataTransfer.effectAllowed = 'move';
                    div.classList.add('dragging');
                });
                div.addEventListener('dragend', () => {
                    div.classList.remove('dragging');
                    clearInsertIndicators();
                    dragSource = null;
                    dragData = null;
                });

                // Drop target: show indicator and handle drop
                div.addEventListener('dragover', (e) => {
                    e.preventDefault();
                    const position = getDropPosition(e, div);
                    showInsertIndicator(div, position);
                });
                div.addEventListener('dragleave', () => clearInsertIndicators());
                div.addEventListener('drop', (e) => {
                    e.preventDefault();
                    clearInsertIndicators();
                    const targetPos = parseInt(div.dataset.pos);
                    const position = getDropPosition(e, div);
                    const insertAt = position === 'before' ? targetPos : targetPos + 1;

                    if (dragSource === 'available') {
                        if (!selectedColumns.find(c => c.index === dragData)) {
                            addColumn(dragData, allColumns[dragData], insertAt);
                        }
                    } else if (dragSource === 'selected') {
                        moveColumn(dragData, insertAt);
                    }
                });

                list.appendChild(div);
                hiddenInputs.innerHTML += `<input type="hidden" name="selected_columns[]" value="${col.index}">`;
                hiddenInputs.innerHTML += `<input type="hidden" name="column_labels[${col.index}]" value="${col.label}">`;
            });

            // Drop zone for empty list or appending to end
            // Always show drop zone — change text based on state
            dropZone.style.display = 'block';
            dropZone.textContent = selectedColumns.length === 0
                ? 'Drag columns here or click + to add'
                : 'Drop here to add to end';
            dropZone.ondragover = (e) => { e.preventDefault(); dropZone.classList.add('over'); };
            dropZone.ondragleave = () => dropZone.classList.remove('over');
            dropZone.ondrop = (e) => {
                e.preventDefault();
                dropZone.classList.remove('over');
                if (dragSource === 'available' && !selectedColumns.find(c => c.index === dragData)) {
                    addColumn(dragData, allColumns[dragData]);
                }
            };

            // Also allow dropping after the last item in selected-list
            list.ondragover = (e) => {
                if (e.target === list) { e.preventDefault(); }
            };
            list.ondrop = (e) => {
                if (e.target === list) {
                    e.preventDefault();
                    if (dragSource === 'available' && !selectedColumns.find(c => c.index === dragData)) {
                        addColumn(dragData, allColumns[dragData]);
                    }
                }
            };

            startBtn.disabled = selectedColumns.length === 0;
            const testBtn = document.getElementById('test-btn');
            if (testBtn) testBtn.disabled = selectedColumns.length === 0;
            colCountMsg.textContent = selectedColumns.length === 0
                ? 'Select at least one column'
                : `${selectedColumns.length} column(s) selected`;

            document.querySelectorAll('#available-list .col-item').forEach(item => {
                const idx = parseInt(item.dataset.index);
                const isSelected = selectedColumns.find(c => c.index === idx);
                item.style.opacity = isSelected ? '0.4' : '1';
                item.querySelector('.add-btn').disabled = !!isSelected;
            });
        }

        // Refresh the embedding preview box to show how selected columns will be formatted
        function refreshPreview() {
            const box = document.getElementById('preview-box');
            if (selectedColumns.length === 0) {
                box.innerHTML = '<span style="color: #5f6368;">Select columns above to see preview...</span>';
                return;
            }
            const hasHeader = document.getElementById('has-header').value === '1';
            let headers = hasHeader ? allColumns : allColumns.map((_, i) => `Column ${i + 1}`);
            let html = '';
            previewRows.forEach((row, ri) => {
                html += `<div class="preview-row"><span style="color: #5f6368; font-size: 11px;">Row ${ri + 1}</span>\n`;
                selectedColumns.forEach(col => {
                    const value = row[col.index] || '';
                    const label = col.label || headers[col.index] || `col${col.index}`;
                    html += `<span class="preview-label">${escapeHtml(label)}</span>: <span class="preview-value">${escapeHtml(value)}</span>\n`;
                });
                html += `</div>`;
            });
            box.innerHTML = html;
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Update the hint text when a knowledge mapping source dropdown changes
        const kmHints = {
            question: { _llm: 'LLM generates a FAQ-style question from representative rows', _none: '', _col: 'Uses this column directly as the question text' },
            symptoms: { _llm: 'Error messages, surface-level phenomena from user reports', _none: '', _col: 'Uses this column directly' },
            root_cause: { _llm: 'Underlying technical cause extracted from resolution data', _none: '', _col: 'Uses this column directly' },
            resolution: { _llm: 'LLM generates resolution steps from representative data', _none: '', _col: 'Uses this column directly as resolution' },
            product: { _llm: 'LLM extracts product names from representative data', _none: 'This field will be empty', _col: 'Uses this column directly' },
            category: { _llm: 'LLM generates a category from cluster content', _none: 'This field will be empty', _col: 'Uses this column directly' },
        };
        function toggleKmLlm(field) {
            const select = document.getElementById('km-' + field + '-source');
            const hint = document.getElementById('km-' + field + '-hint');
            if (!select || !hint) return;
            const val = select.value;
            if (val === '_llm') hint.textContent = kmHints[field]._llm;
            else if (val === '_none') hint.textContent = kmHints[field]._none;
            else hint.textContent = kmHints[field]._col;
        }

        // Toggle visibility of clustering algorithm-specific parameter inputs
        document.getElementById('clustering-method').addEventListener('change', function() {
            ['params-hdbscan', 'params-kmeans', 'params-agglomerative', 'params-leiden'].forEach(
                id => document.getElementById(id).style.display = 'none'
            );
            document.getElementById('params-' + this.value).style.display = '';
        });

        renderSelected();
        setupAvailableDrag();
@endsection
