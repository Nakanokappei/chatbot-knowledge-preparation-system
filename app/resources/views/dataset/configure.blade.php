@extends('layouts.app')
@section('title', "Configure Dataset — {$dataset->name}")

@section('extra-styles')
        .page-content { padding: 24px; max-width: 1100px; margin: 0 auto; }
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
        .mapper-panel { flex: 1; border: 1px solid #e5e5e7; border-radius: 10px; padding: 12px; background: #fafafa; }
        .mapper-panel h3 { font-size: 13px; font-weight: 600; margin-bottom: 8px; color: #5f6368; }
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
        .drop-zone { min-height: 60px; border: 2px dashed #d2d2d7; border-radius: 8px; padding: 16px; text-align: center; color: #5f6368; font-size: 13px; transition: all 0.15s; }
        .drop-zone.over { border-color: #0071e3; background: #f0f8ff; }

        /* Preview */
        .preview-box { background: #1d1d1f; color: #e5e5e7; border-radius: 10px; padding: 16px; font-family: 'SF Mono', 'Menlo', monospace; font-size: 12px; line-height: 1.6; max-height: 400px; overflow-y: auto; white-space: pre-wrap; }
        .preview-row { margin-bottom: 12px; padding-bottom: 12px; border-bottom: 1px solid #333; }
        .preview-row:last-child { border-bottom: none; margin-bottom: 0; padding-bottom: 0; }
        .preview-label { color: #64d2ff; }
        .preview-value { color: #fff; }

        .pipeline-options { display: flex; gap: 12px; align-items: flex-end; flex-wrap: wrap; margin-top: 12px; }
@endsection

@section('body')
    <div class="page-content">
        <a href="{{ route('workspace.index') }}" style="font-size: 13px; color: #0071e3; text-decoration: none;">&larr; {{ __('ui.nav_workspace') }}</a>
        <h1 style="margin-top: 12px; font-size: 22px; font-weight: 600;">{{ __('ui.configure_dataset') }}: {{ $dataset->name }}</h1>
        <p style="color: #5f6368; font-size: 13px; margin-bottom: 24px;">{{ $dataset->original_filename }} — {{ $totalLines }} data rows — Encoding: {{ $detectedEncoding }}</p>

        @if(session('error'))
            <div style="color: #ff3b30; font-size: 13px; margin-bottom: 12px;">✗ {{ session('error') }}</div>
        @endif

        <form id="config-form" method="POST" action="{{ route('dataset.finalize', $dataset) }}">
            @csrf

            <!-- Basic Settings -->
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
                        <select id="encoding" name="encoding">
                            <option value="UTF-8" {{ $detectedEncoding === 'UTF-8' ? 'selected' : '' }}>UTF-8</option>
                            <option value="Shift_JIS" {{ $detectedEncoding === 'Shift_JIS' ? 'selected' : '' }}>Shift_JIS</option>
                            <option value="EUC-JP" {{ $detectedEncoding === 'EUC-JP' ? 'selected' : '' }}>EUC-JP</option>
                            <option value="ISO-8859-1" {{ $detectedEncoding === 'ISO-8859-1' ? 'selected' : '' }}>ISO-8859-1 (Latin)</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Column Mapper -->
            <div class="card">
                <h2>{{ __('ui.select_columns') }}</h2>
                <p style="font-size: 12px; color: #5f6368; margin-bottom: 12px;">
                    Click <strong>+</strong> to add columns. Drag to reorder. Edit the label shown before each value.
                </p>
                <div class="mapper">
                    <div class="mapper-panel" id="available-panel">
                        <h3>{{ __('ui.available_columns') }}</h3>
                        <div id="available-list">
                            @foreach($columns as $i => $col)
                            <div class="col-item" data-index="{{ $i }}">
                                <span class="col-index">#{{ $i + 1 }}</span>
                                <span class="col-name">{{ $col }}</span>
                                <span style="font-size: 11px; color: #5f6368; max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                    {{ isset($previewRows[0][$i]) ? Str::limit($previewRows[0][$i], 40) : '' }}
                                </span>
                                <button type="button" class="add-btn" onclick="addColumn({{ $i }}, '{{ addslashes($col) }}')" title="Add to embedding">+</button>
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

            <!-- Preview -->
            <div class="card">
                <h2>{{ __('ui.embedding_preview') }}</h2>
                <div class="preview-box" id="preview-box">
                    <span style="color: #5f6368;">Select columns above to see preview...</span>
                </div>
            </div>

            <!-- Pipeline Settings -->
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
                <div style="margin-top: 16px; display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
                    <button type="submit" class="btn btn-success" id="start-btn" disabled style="padding: 10px 24px; font-size: 14px;">
                        {{ __('ui.start_pipeline') }}
                    </button>
                    <button type="submit" name="test_mode" value="500" class="btn" id="test-btn" disabled
                        style="padding: 10px 24px; font-size: 14px; background: #ff9500; color: #fff; border: none; border-radius: 8px; cursor: pointer;">
                        🧪 {{ __('ui.test_pipeline') ?? 'Test (max 500 rows)' }}
                    </button>
                    <span id="col-count-msg" style="font-size: 13px; color: #5f6368;">{{ __('ui.select_one_column') }}</span>
                </div>
            </div>

            <div id="hidden-inputs"></div>
        </form>
    </div>
@endsection

@section('scripts')
        const allColumns = @json($columns);
        const previewRows = @json($previewRows);
        let selectedColumns = [];

        function addColumn(index, name) {
            if (selectedColumns.find(c => c.index === index)) return;
            selectedColumns.push({ index: index, label: name });
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
            const item = selectedColumns.splice(fromIdx, 1)[0];
            selectedColumns.splice(toIdx, 0, item);
            renderSelected();
            refreshPreview();
        }

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
                div.addEventListener('dragstart', (e) => { e.dataTransfer.setData('text/plain', pos); div.classList.add('dragging'); });
                div.addEventListener('dragend', () => div.classList.remove('dragging'));
                div.addEventListener('dragover', (e) => e.preventDefault());
                div.addEventListener('drop', (e) => { e.preventDefault(); moveColumn(parseInt(e.dataTransfer.getData('text/plain')), pos); });
                list.appendChild(div);

                hiddenInputs.innerHTML += `<input type="hidden" name="selected_columns[]" value="${col.index}">`;
                hiddenInputs.innerHTML += `<input type="hidden" name="column_labels[${col.index}]" value="${col.label}">`;
            });

            dropZone.style.display = selectedColumns.length === 0 ? 'block' : 'none';
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

        // Clustering method parameter toggle
        document.getElementById('clustering-method').addEventListener('change', function() {
            ['params-hdbscan', 'params-kmeans', 'params-agglomerative', 'params-leiden'].forEach(
                id => document.getElementById(id).style.display = 'none'
            );
            document.getElementById('params-' + this.value).style.display = '';
        });

        renderSelected();
@endsection
