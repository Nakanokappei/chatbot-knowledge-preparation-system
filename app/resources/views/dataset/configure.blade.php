<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configure Dataset — {{ $dataset->name }}</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f5f5f7; color: #1d1d1f; }
        .container { max-width: 1100px; margin: 0 auto; padding: 24px; }
        h1 { font-size: 22px; font-weight: 600; margin-bottom: 4px; }
        .subtitle { color: #86868b; font-size: 13px; margin-bottom: 24px; }
        .card { background: #fff; border-radius: 12px; padding: 20px; margin-bottom: 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.08); }
        .card h2 { font-size: 15px; font-weight: 600; margin-bottom: 12px; }
        .row { display: flex; gap: 16px; }
        .col { flex: 1; }
        label { font-size: 12px; color: #86868b; display: block; margin-bottom: 4px; }
        select, input[type=text] { padding: 8px 12px; border: 1px solid #d2d2d7; border-radius: 8px; font-size: 14px; width: 100%; }
        .btn { display: inline-block; padding: 10px 24px; border-radius: 8px; border: none; font-size: 14px; font-weight: 500; cursor: pointer; text-decoration: none; }
        .btn-primary { background: #0071e3; color: #fff; }
        .btn-primary:hover { background: #0077ed; }
        .btn-success { background: #30d158; color: #fff; }
        .btn-success:hover { background: #28b84c; }
        .btn-sm { padding: 6px 14px; font-size: 13px; }
        .btn-outline { background: transparent; border: 1px solid #d2d2d7; color: #1d1d1f; }

        /* Column mapper */
        .mapper { display: flex; gap: 16px; min-height: 300px; }
        .mapper-panel { flex: 1; border: 1px solid #e5e5e7; border-radius: 10px; padding: 12px; background: #fafafa; }
        .mapper-panel h3 { font-size: 13px; font-weight: 600; margin-bottom: 8px; color: #86868b; }
        .mapper-panel.selected { background: #f0f8ff; border-color: #0071e3; }
        .col-item {
            display: flex; align-items: center; gap: 8px; padding: 8px 12px;
            background: #fff; border: 1px solid #e5e5e7; border-radius: 8px;
            margin-bottom: 6px; cursor: grab; font-size: 13px; transition: all 0.15s;
        }
        .col-item:hover { border-color: #0071e3; background: #f0f8ff; }
        .col-item.dragging { opacity: 0.5; }
        .col-item .col-index { font-size: 11px; color: #86868b; min-width: 20px; }
        .col-item .col-name { flex: 1; font-weight: 500; }
        .col-item .col-label-input {
            width: 120px; padding: 4px 8px; border: 1px solid #d2d2d7;
            border-radius: 6px; font-size: 12px;
        }
        .col-item .remove-btn {
            background: none; border: none; color: #ff3b30; cursor: pointer;
            font-size: 16px; padding: 0 4px; line-height: 1;
        }
        .col-item .add-btn {
            background: none; border: none; color: #0071e3; cursor: pointer;
            font-size: 18px; padding: 0 4px; line-height: 1;
        }
        .col-item .handle { cursor: grab; color: #c7c7cc; font-size: 14px; }
        .drop-zone {
            min-height: 60px; border: 2px dashed #d2d2d7; border-radius: 8px;
            padding: 16px; text-align: center; color: #86868b; font-size: 13px;
            transition: all 0.15s;
        }
        .drop-zone.over { border-color: #0071e3; background: #f0f8ff; }

        /* Preview */
        .preview-box {
            background: #1d1d1f; color: #e5e5e7; border-radius: 10px;
            padding: 16px; font-family: 'SF Mono', 'Menlo', monospace;
            font-size: 12px; line-height: 1.6; max-height: 400px;
            overflow-y: auto; white-space: pre-wrap;
        }
        .preview-row { margin-bottom: 12px; padding-bottom: 12px; border-bottom: 1px solid #333; }
        .preview-row:last-child { border-bottom: none; margin-bottom: 0; padding-bottom: 0; }
        .preview-row-no { color: #86868b; font-size: 11px; }
        .preview-label { color: #64d2ff; }
        .preview-value { color: #fff; }

        /* Pipeline options */
        .pipeline-options { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; margin-top: 12px; }

        .info { background: #f0f8ff; border: 1px solid #b3d7ff; border-radius: 8px; padding: 12px; font-size: 13px; color: #0051a8; }
        .error-msg { color: #ff3b30; font-size: 13px; margin-top: 8px; }
    </style>
</head>
<body>
    <div class="container">
        <a href="{{ route('dashboard') }}" style="font-size: 13px; color: #0071e3; text-decoration: none;">← Dashboard</a>
        <h1 style="margin-top: 12px;">Configure Dataset: {{ $dataset->name }}</h1>
        <p class="subtitle">{{ $dataset->original_filename }} — {{ $totalLines }} data rows detected — Encoding: {{ $detectedEncoding }}</p>

        @if(session('error'))
            <div class="error-msg" style="margin-bottom: 12px;">✗ {{ session('error') }}</div>
        @endif

        <form id="config-form" method="POST" action="{{ route('dataset.finalize', $dataset) }}">
            @csrf

            <!-- Options Row -->
            <div class="card">
                <h2>Basic Settings</h2>
                <div class="row">
                    <div class="col">
                        <label>Dataset name</label>
                        <input type="text" name="dataset_name" value="{{ $dataset->name }}"
                            style="padding: 8px 12px; border: 1px solid #d2d2d7; border-radius: 8px; font-size: 14px; width: 100%;">
                    </div>
                    <div class="col">
                        <label>First row is</label>
                        <select id="has-header" name="has_header" onchange="refreshPreview()">
                            <option value="1" selected>Header (column names)</option>
                            <option value="0">Data (no header)</option>
                        </select>
                    </div>
                    <div class="col">
                        <label>Character encoding</label>
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
                <h2>Select Columns for Embedding</h2>
                <p style="font-size: 12px; color: #86868b; margin-bottom: 12px;">
                    Click <strong>+</strong> to add columns to the embedding. Drag to reorder. Edit the label shown before each value.
                </p>
                <div class="mapper">
                    <!-- Available columns (left) -->
                    <div class="mapper-panel" id="available-panel">
                        <h3>Available Columns</h3>
                        <div id="available-list">
                            @foreach($columns as $i => $col)
                            <div class="col-item" data-index="{{ $i }}">
                                <span class="col-index">#{{ $i + 1 }}</span>
                                <span class="col-name">{{ $col }}</span>
                                <span class="col-sample" style="font-size: 11px; color: #86868b; max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                    {{ isset($previewRows[0][$i]) ? Str::limit($previewRows[0][$i], 40) : '' }}
                                </span>
                                <button type="button" class="add-btn" onclick="addColumn({{ $i }}, '{{ addslashes($col) }}')" title="Add to embedding">+</button>
                            </div>
                            @endforeach
                        </div>
                    </div>

                    <!-- Selected columns (right) -->
                    <div class="mapper-panel selected" id="selected-panel">
                        <h3>Embedding Columns (in order)</h3>
                        <div id="selected-list">
                            <!-- Populated by JS -->
                        </div>
                        <div class="drop-zone" id="drop-zone">
                            Drop columns here or click + on the left
                        </div>
                    </div>
                </div>
            </div>

            <!-- Preview -->
            <div class="card">
                <h2>Embedding Text Preview</h2>
                <div class="preview-box" id="preview-box">
                    <span style="color: #86868b;">Select columns above to see preview...</span>
                </div>
            </div>

            <!-- Pipeline Options + Submit -->
            <div class="card">
                <h2>Pipeline Settings</h2>
                <div class="pipeline-options">
                    <div>
                        <label>LLM Model</label>
                        <select name="llm_model_id" style="padding: 8px 12px; border: 1px solid #d2d2d7; border-radius: 8px; font-size: 14px;">
                            @foreach($llmModels as $model)
                                <option value="{{ $model->model_id }}" @if($model->is_default) selected @endif>{{ $model->display_name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label>Clustering Method</label>
                        <select name="clustering_method" id="clustering-method" style="padding: 8px 12px; border: 1px solid #d2d2d7; border-radius: 8px; font-size: 14px;">
                            <option value="hdbscan" selected>HDBSCAN</option>
                            <option value="kmeans">K-Means</option>
                            <option value="agglomerative">Agglomerative</option>
                            <option value="leiden">Leiden</option>
                        </select>
                    </div>
                </div>
                <div style="margin-top: 16px; display: flex; gap: 12px; align-items: center;">
                    <button type="submit" class="btn btn-success" id="start-btn" disabled>
                        Start Pipeline
                    </button>
                    <span id="col-count-msg" style="font-size: 13px; color: #86868b;">Select at least one column</span>
                </div>
            </div>

            <!-- Hidden inputs for selected columns (populated by JS) -->
            <div id="hidden-inputs"></div>
        </form>
    </div>

    <script>
        // State: selected columns in order
        const allColumns = @json($columns);
        const previewRows = @json($previewRows);
        let selectedColumns = []; // [{index: 0, label: 'subject'}, ...]

        function addColumn(index, name) {
            // Don't add if already selected
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
                        onchange="updateLabel(${col.index}, this.value)"
                        placeholder="Label">
                    <button type="button" class="remove-btn" onclick="removeColumn(${col.index})" title="Remove">×</button>
                `;

                // Drag & drop reordering
                div.addEventListener('dragstart', (e) => {
                    e.dataTransfer.setData('text/plain', pos);
                    div.classList.add('dragging');
                });
                div.addEventListener('dragend', () => div.classList.remove('dragging'));
                div.addEventListener('dragover', (e) => e.preventDefault());
                div.addEventListener('drop', (e) => {
                    e.preventDefault();
                    const from = parseInt(e.dataTransfer.getData('text/plain'));
                    moveColumn(from, pos);
                });

                list.appendChild(div);

                // Hidden inputs for form submission
                const input1 = document.createElement('input');
                input1.type = 'hidden';
                input1.name = 'selected_columns[]';
                input1.value = col.index;
                hiddenInputs.appendChild(input1);

                const input2 = document.createElement('input');
                input2.type = 'hidden';
                input2.name = `column_labels[${col.index}]`;
                input2.value = col.label;
                hiddenInputs.appendChild(input2);
            });

            dropZone.style.display = selectedColumns.length === 0 ? 'block' : 'none';
            startBtn.disabled = selectedColumns.length === 0;
            colCountMsg.textContent = selectedColumns.length === 0
                ? 'Select at least one column'
                : `${selectedColumns.length} column(s) selected`;

            // Grey out already-added columns in available list
            document.querySelectorAll('#available-list .col-item').forEach(item => {
                const idx = parseInt(item.dataset.index);
                const isSelected = selectedColumns.find(c => c.index === idx);
                item.style.opacity = isSelected ? '0.4' : '1';
                item.querySelector('.add-btn').disabled = !!isSelected;
            });
        }

        function refreshPreview() {
            const box = document.getElementById('preview-box');
            const hasHeader = document.getElementById('has-header').value === '1';

            if (selectedColumns.length === 0) {
                box.innerHTML = '<span style="color: #86868b;">Select columns above to see preview...</span>';
                return;
            }

            // Determine headers
            let headers;
            let rowData;
            if (hasHeader) {
                headers = allColumns;
                rowData = previewRows;
            } else {
                headers = allColumns.map((_, i) => `Column ${i + 1}`);
                // First preview row is actually the header row treated as data
                rowData = previewRows;
            }

            let html = '';
            rowData.forEach((row, ri) => {
                html += `<div class="preview-row">`;
                html += `<span class="preview-row-no">Row ${ri + 1}</span>\n`;
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

        // Initialize
        renderSelected();
    </script>
</body>
</html>
