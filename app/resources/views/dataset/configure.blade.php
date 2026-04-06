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


@endsection

@section('body')
    <div class="configure-overlay" onclick="if(event.target===this) window.location='{{ route('workspace.index') }}'">
        <div class="configure-panel">
            <div class="configure-titlebar">
                <h1>{{ $isReconfigure ? __('ui.new_cluster') : __('ui.new_dataset') }}: {{ $dataset->name }}</h1>
                <a href="{{ route('workspace.index') }}" class="close-btn" title="{{ __('ui.close') }}">&times;</a>
            </div>
            <div class="configure-body">
                <p style="color: #5f6368; font-size: 13px; margin-bottom: 24px;">{{ $dataset->original_filename }} — {{ number_format($totalLines) }} {{ __('ui.data_rows') }} — {{ __('ui.encoding') }}: {{ $detectedEncoding }}</p>

                @if(session('error'))
                    <div style="color: #ff3b30; font-size: 13px; margin-bottom: 12px;">✗ {{ session('error') }}</div>
                @endif

                <form id="config-form" method="POST" action="{{ route('dataset.finalize', $dataset) }}">
                    @csrf

            {{-- Basic settings: dataset name, header row, encoding, models, and language bias --}}
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

                {{-- Model selection and language bias: moved here from pipeline settings
                     so that embedding model and LLM model are shared across all clustering configs --}}
                <div class="row" style="margin-top: 12px;">
                    <div class="col">
                        <label>{{ __('ui.embedding_model') }}</label>
                        <select name="embedding_model_id" style="padding: 8px 12px; border: 1px solid #d2d2d7; border-radius: 8px; font-size: 14px;">
                            @foreach($embeddingModels as $em)
                                @php $provider = $em->provider ?: (str_starts_with($em->model_id, 'text-embedding-') ? 'openai' : 'bedrock'); @endphp
                                <option value="{{ $em->model_id }}" @if($loop->first) selected @endif>
                                    [{{ ucfirst($provider) }}] {{ $em->display_name }} ({{ $em->dimension }}d)
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col">
                        <label>{{ __('ui.llm_model') }}</label>
                        <select name="llm_model_id" style="padding: 8px 12px; border: 1px solid #d2d2d7; border-radius: 8px; font-size: 14px;">
                            @foreach($llmModels as $model)
                                <option value="{{ $model->model_id }}" @if($loop->first) selected @endif>{{ $model->display_name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div style="margin-top: 10px;">
                    <label style="display: flex; align-items: center; gap: 8px; font-size: 13px; color: #1d1d1f; cursor: pointer;">
                        <input type="checkbox" name="remove_language_bias" value="1" checked
                            style="width: 16px; height: 16px; accent-color: #0071e3;">
                        {{ __('ui.remove_language_bias') }}
                    </label>
                </div>
            </div>

            {{-- Descriptions: LLM-generated dataset and per-column descriptions (editable) --}}
            <div class="card">
                <h2>{{ __('ui.descriptions') }}</h2>
                <p style="font-size: 12px; color: #5f6368; margin-bottom: 12px;">
                    {{ __('ui.descriptions_hint') }}
                </p>

                {{-- LLM generate button with model selector --}}
                <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 16px; padding: 8px 12px; background: #f8f9fa; border-radius: 8px;">
                    <select id="desc-llm-model" style="padding: 5px 8px; border: 1px solid #d2d2d7; border-radius: 6px; font-size: 12px; flex: 1; min-width: 0;">
                        @foreach($llmModels as $model)
                            <option value="{{ $model->model_id }}" @if($model->is_default) selected @endif>{{ $model->display_name }}</option>
                        @endforeach
                    </select>
                    <button type="button" id="btn-generate-descriptions" onclick="generateDescriptions()"
                        style="padding: 5px 12px; background: #fff; border: 1px solid #d2d2d7; border-radius: 6px; font-size: 12px; cursor: pointer; white-space: nowrap; flex-shrink: 0;">
                        {{ __('ui.generate_descriptions_with_llm') }}
                    </button>
                    <span id="desc-generate-status" style="font-size: 11px; color: #5f6368;"></span>
                </div>

                <div style="margin-bottom: 16px;">
                    <label style="font-weight: 500; font-size: 13px;">{{ __('ui.dataset_description') }}</label>
                    <textarea name="dataset_description" rows="2"
                        style="width: 100%; padding: 8px 10px; border: 1px solid #d2d2d7; border-radius: 6px; font-size: 13px; resize: vertical;"
                        placeholder="{{ __('ui.dataset_description_placeholder') }}">{{ $dataset->schema_json['dataset_description'] ?? '' }}</textarea>
                </div>
                <div>
                    <label style="font-weight: 500; font-size: 13px; margin-bottom: 8px; display: block;">{{ __('ui.column_descriptions') }}</label>
                    <div style="display: grid; grid-template-columns: 160px 1fr; gap: 6px 12px; align-items: center;">
                        @php $colDescs = $dataset->schema_json['column_descriptions'] ?? []; @endphp
                        @foreach($columns as $col)
                            <label style="font-size: 12px; color: #1d1d1f; font-weight: 500; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="{{ $col }}">{{ $col }}</label>
                            <input type="text" name="column_descriptions[{{ $col }}]"
                                value="{{ $colDescs[$col] ?? '' }}"
                                style="padding: 5px 8px; border: 1px solid #d2d2d7; border-radius: 6px; font-size: 12px;"
                                placeholder="{{ __('ui.column_description_placeholder') }}">
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- Column mapper: drag-and-drop interface to select and order columns for embedding --}}
            <div class="card">
                <h2>{{ __('ui.clustering_columns') }}</h2>
                <p style="font-size: 12px; color: #5f6368; margin-bottom: 12px;">
                    {{ __('ui.clustering_columns_hint') }}
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
                                <button type="button" class="add-btn" onclick="addColumn({{ $columnIndex }}, '{{ addslashes($columnName) }}')" title="{{ __('ui.add_to_embedding') }}">+</button>
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
                    <span style="color: #5f6368;">{{ __('ui.preview_select_columns') }}</span>
                </div>
            </div>

            {{-- Knowledge structure mapping: map CSV columns to KU fields or let LLM generate them --}}
            <div class="card">
                <h2>{{ __('ui.knowledge_structure') }}</h2>
                <p style="font-size: 12px; color: #5f6368; margin-bottom: 16px;">
                    {{ __('ui.knowledge_structure_hint') }}
                </p>

                <div style="display: grid; grid-template-columns: 120px 1fr; gap: 10px 16px; align-items: start;">
                    {{-- Question --}}
                    <label style="font-weight: 500; font-size: 13px;">{{ __('ui.km_question') }}</label>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <select name="km_question_source" id="km-question-source" onchange="toggleKmLlm('question')"
                            style="padding: 6px 10px; border: 1px solid #d2d2d7; border-radius: 6px; font-size: 13px; width: 220px; flex-shrink: 0;">
                            <option value="_llm">{{ __('ui.generate_with_llm') }}</option>
                            @foreach($columns as $columnIndex => $columnName)
                                <option value="{{ $columnIndex }}">{{ $columnName }}</option>
                            @endforeach
                        </select>
                        <span id="km-question-hint" style="font-size: 11px; color: #5f6368; min-width: 0;">{{ __('ui.km_question_hint') }}</span>
                    </div>

                    {{-- Symptoms --}}
                    <label style="font-weight: 500; font-size: 13px;">{{ __('ui.km_symptoms') }}</label>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <select name="km_symptoms_source" id="km-symptoms-source" onchange="toggleKmLlm('symptoms')"
                            style="padding: 6px 10px; border: 1px solid #d2d2d7; border-radius: 6px; font-size: 13px; width: 220px; flex-shrink: 0;">
                            <option value="_llm">{{ __('ui.extract_with_llm') }}</option>
                            @foreach($columns as $columnIndex => $columnName)
                                <option value="{{ $columnIndex }}">{{ $columnName }}</option>
                            @endforeach
                        </select>
                        <span id="km-symptoms-hint" style="font-size: 11px; color: #5f6368; min-width: 0;">{{ __('ui.km_symptoms_hint') }}</span>
                    </div>

                    {{-- Root Cause --}}
                    <label style="font-weight: 500; font-size: 13px;">{{ __('ui.km_root_cause') }}</label>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <select name="km_root_cause_source" id="km-root_cause-source" onchange="toggleKmLlm('root_cause')"
                            style="padding: 6px 10px; border: 1px solid #d2d2d7; border-radius: 6px; font-size: 13px; width: 220px; flex-shrink: 0;">
                            <option value="_llm">{{ __('ui.extract_with_llm') }}</option>
                            @foreach($columns as $columnIndex => $columnName)
                                <option value="{{ $columnIndex }}">{{ $columnName }}</option>
                            @endforeach
                        </select>
                        <span id="km-root_cause-hint" style="font-size: 11px; color: #5f6368; min-width: 0;">{{ __('ui.km_root_cause_hint') }}</span>
                    </div>

                    {{-- Resolution --}}
                    <label style="font-weight: 500; font-size: 13px;">{{ __('ui.km_resolution') }}</label>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <select name="km_resolution_source" id="km-resolution-source" onchange="toggleKmLlm('resolution')"
                            style="padding: 6px 10px; border: 1px solid #d2d2d7; border-radius: 6px; font-size: 13px; width: 220px; flex-shrink: 0;">
                            <option value="_llm">{{ __('ui.generate_with_llm') }}</option>
                            @foreach($columns as $columnIndex => $columnName)
                                <option value="{{ $columnIndex }}">{{ $columnName }}</option>
                            @endforeach
                        </select>
                        <span id="km-resolution-hint" style="font-size: 11px; color: #5f6368; min-width: 0;">{{ __('ui.km_resolution_hint') }}</span>
                    </div>

                    {{-- Primary Filter (required, column-only — no LLM option) --}}
                    <label style="font-weight: 500; font-size: 13px;">{{ __('ui.km_primary_filter') }}</label>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <select name="km_primary_filter_source" id="km-primary_filter-source" onchange="toggleKmLlm('primary_filter')" required
                            style="padding: 6px 10px; border: 1px solid #d2d2d7; border-radius: 6px; font-size: 13px; width: 220px; flex-shrink: 0;">
                            @foreach($columns as $columnIndex => $columnName)
                                <option value="{{ $columnIndex }}">{{ $columnName }}</option>
                            @endforeach
                        </select>
                        <span id="km-primary_filter-hint" style="font-size: 11px; color: #5f6368; min-width: 0;">{{ __('ui.km_primary_filter_hint') }}</span>
                    </div>
                    {{-- Primary filter label spans both columns --}}
                    <div></div>
                    <div style="display: flex; align-items: center; gap: 8px; margin-top: -4px; margin-bottom: 4px;">
                        <input type="text" name="primary_filter_label" placeholder="{{ __('ui.primary_filter_label_placeholder') }}"
                            value="{{ $dataset->schema_json['primary_filter_label'] ?? '' }}"
                            style="padding: 6px 10px; border: 1px solid #d2d2d7; border-radius: 6px; font-size: 13px; width: 220px; flex-shrink: 0;">
                        <span style="font-size: 11px; color: #5f6368;">{{ __('ui.primary_filter_label_hint') }}</span>
                    </div>

                    {{-- Category --}}
                    <label style="font-weight: 500; font-size: 13px;">{{ __('ui.km_category') }}</label>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <select name="km_category_source" id="km-category-source" onchange="toggleKmLlm('category')"
                            style="padding: 6px 10px; border: 1px solid #d2d2d7; border-radius: 6px; font-size: 13px; width: 220px; flex-shrink: 0;">
                            <option value="_none">{{ __('ui.not_used') }}</option>
                            <option value="_llm">{{ __('ui.generate_with_llm') }}</option>
                            @foreach($columns as $columnIndex => $columnName)
                                <option value="{{ $columnIndex }}">{{ $columnName }}</option>
                            @endforeach
                        </select>
                        <span id="km-category-hint" style="font-size: 11px; color: #5f6368; min-width: 0;">{{ __('ui.km_category_hint') }}</span>
                    </div>

                    {{-- Reference URL --}}
                    <label style="font-weight: 500; font-size: 13px;">{{ __('ui.km_reference_url') }}</label>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <select name="km_reference_url_source" id="km-reference_url-source" onchange="toggleKmLlm('reference_url')"
                            style="padding: 6px 10px; border: 1px solid #d2d2d7; border-radius: 6px; font-size: 13px; width: 220px; flex-shrink: 0;">
                            <option value="_none">{{ __('ui.not_used') }}</option>
                            <option value="_llm">{{ __('ui.extract_with_llm') }}</option>
                            @foreach($columns as $columnIndex => $columnName)
                                <option value="{{ $columnIndex }}">{{ $columnName }}</option>
                            @endforeach
                        </select>
                        <span id="km-reference_url-hint" style="font-size: 11px; color: #5f6368; min-width: 0;">{{ __('ui.km_reference_url_hint') }}</span>
                    </div>

                </div>

                {{-- LLM fallback option — outside the grid so it spans full width --}}
                <div style="margin-top: 16px; padding-top: 12px; border-top: 1px solid #e5e5e7;">
                    <label style="display: flex; align-items: center; gap: 8px; font-size: 13px; cursor: pointer;">
                        <input type="hidden" name="llm_fallback" value="0">
                        <input type="checkbox" name="llm_fallback" value="1" checked
                            style="width: 16px; height: 16px; flex-shrink: 0; accent-color: #0071e3;">
                        <span style="font-weight: 500; white-space: nowrap;">{{ __('ui.llm_fallback') }}</span>
                        <span style="color: #5f6368; font-weight: 400;">— {{ __('ui.llm_fallback_hint') }}</span>
                    </label>
                </div>
            </div>

            {{-- Clustering patterns: card-based repeatable configurations.
                 Each card represents a clustering method+parameter set.
                 Job #1 runs full pipeline; #2..N reuse the same embedding. --}}
            <div class="card">
                <h2>{{ __('ui.clustering_patterns') }}</h2>
                <p style="font-size: 12px; color: #5f6368; margin-bottom: 12px;">
                    {{ __('ui.clustering_patterns_hint') }}
                </p>

                <div id="clustering-config-list"></div>

                <button type="button" id="add-clustering-config" onclick="addClusteringConfig()"
                    style="width: 100%; padding: 10px 16px; font-size: 13px; font-weight: 500; background: #fff; border: 2px dashed #d2d2d7; border-radius: 10px; cursor: pointer; color: #0071e3; margin-top: 4px; transition: background 0.15s;"
                    onmouseover="this.style.background='#f0f8ff'" onmouseout="this.style.background='#fff'">
                    + {{ __('ui.add_pattern') }}
                </button>
            </div>

            {{-- Hidden template for clustering config cards (cloned by JS) --}}
            <template id="clustering-config-template">
                <div class="clustering-config-row" style="position: relative; padding: 14px 16px; background: #fafafa; border: 1px solid #e5e5e7; border-radius: 10px; margin-bottom: 8px;">
                    {{-- Card header: number badge and remove button --}}
                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px;">
                        <span class="config-number" style="font-size: 12px; font-weight: 600; color: #fff; background: #0071e3; border-radius: 4px; padding: 2px 8px;">Pattern #1</span>
                        <button type="button" class="cc-remove" onclick="removeClusteringConfig(this)"
                            style="background: #f0f0f2; border: 1px solid #d2d2d7; border-radius: 6px; cursor: pointer; color: #ff3b30; font-size: 12px; padding: 3px 10px; white-space: nowrap;"
                            title="{{ __('ui.delete') }}">{{ __('ui.delete_pattern') }}</button>
                    </div>
                    {{-- Method selector and parameters --}}
                    <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                        <select class="cc-method" onchange="updateConfigParams(this)"
                            style="padding: 7px 12px; border: 1px solid #d2d2d7; border-radius: 8px; font-size: 13px; font-weight: 500;">
                            <option value="leiden" selected>HNSW + Leiden</option>
                            <option value="hdbscan">HDBSCAN</option>
                            <option value="kmeans">K-Means++</option>
                            <option value="agglomerative">Agglomerative</option>
                        </select>
                        <span class="cc-params-hdbscan" style="display:none;">
                            <label style="font-size:12px;">min_cluster_size</label>
                            <input type="number" class="cc-hdbscan-min-cluster" value="15" min="2" max="500" style="width:60px;padding:5px 8px;border:1px solid #d2d2d7;border-radius:6px;font-size:12px;">
                            <label style="font-size:12px;margin-left:6px;">min_samples</label>
                            <input type="number" class="cc-hdbscan-min-samples" value="5" min="1" max="100" style="width:50px;padding:5px 8px;border:1px solid #d2d2d7;border-radius:6px;font-size:12px;">
                        </span>
                        <span class="cc-params-kmeans" style="display:none;">
                            <label style="font-size:12px;">n_clusters</label>
                            <input type="number" class="cc-kmeans-n" value="10" min="2" max="200" style="width:60px;padding:5px 8px;border:1px solid #d2d2d7;border-radius:6px;font-size:12px;">
                        </span>
                        <span class="cc-params-agglomerative" style="display:none;">
                            <label style="font-size:12px;">n_clusters</label>
                            <input type="number" class="cc-agg-n" value="10" min="2" max="200" style="width:60px;padding:5px 8px;border:1px solid #d2d2d7;border-radius:6px;font-size:12px;">
                            <label style="font-size:12px;margin-left:6px;">linkage</label>
                            <select class="cc-agg-linkage" style="padding:5px 8px;border:1px solid #d2d2d7;border-radius:6px;font-size:12px;">
                                <option value="ward">ward</option><option value="complete">complete</option>
                                <option value="average">average</option><option value="single">single</option>
                            </select>
                        </span>
                        <span class="cc-params-leiden">
                            <label style="font-size:12px;">n_neighbors</label>
                            <input type="number" class="cc-leiden-neighbors" value="15" min="5" max="100" style="width:60px;padding:5px 8px;border:1px solid #d2d2d7;border-radius:6px;font-size:12px;">
                            <label style="font-size:12px;margin-left:6px;">resolution</label>
                            <input type="number" class="cc-leiden-resolution" value="1.0" min="0.1" max="10.0" step="0.1" style="width:60px;padding:5px 8px;border:1px solid #d2d2d7;border-radius:6px;font-size:12px;">
                        </span>
                    </div>
                </div>
            </template>

            {{-- Action buttons: start/queue pipeline --}}
            <div class="card" style="display: flex; gap: 12px; align-items: center; flex-wrap: nowrap;">
                @if($isReconfigure)
                <button type="submit" class="btn" id="start-btn" disabled
                    style="padding: 10px 24px; font-size: 14px; white-space: nowrap; background: #0071e3; color: #fff; border: none; border-radius: 8px; cursor: pointer;">
                    {{ __('ui.create_cluster') }}
                </button>
                <button type="submit" name="test_mode" value="500" class="btn" id="test-btn" disabled
                    style="padding: 10px 24px; font-size: 14px; white-space: nowrap; background: #fff; color: #0071e3; border: 2px solid #0071e3; border-radius: 8px; cursor: pointer;">
                    {{ __('ui.test_cluster') }}
                </button>
                @elseif($hasRunningPipeline)
                <button type="submit" class="btn btn-primary" id="start-btn" disabled
                    style="padding: 10px 24px; font-size: 14px; white-space: nowrap; background: #0071e3; color: #fff; border: none; border-radius: 8px; cursor: pointer;">
                    {{ __('ui.queue_pipeline') }}
                </button>
                <button type="submit" name="test_mode" value="500" class="btn" id="test-btn" disabled
                    style="padding: 10px 24px; font-size: 14px; white-space: nowrap; background: #fff; color: #0071e3; border: 1.5px solid #0071e3; border-radius: 8px; cursor: pointer;">
                    {{ __('ui.queue_test') }}
                </button>
                @else
                <button type="submit" class="btn btn-primary" id="start-btn" disabled
                    style="padding: 10px 24px; font-size: 14px; white-space: nowrap; background: #0071e3; color: #fff; border: none; border-radius: 8px; cursor: pointer;">
                    {{ __('ui.start_pipeline') }}
                </button>
                <button type="submit" name="test_mode" value="500" class="btn" id="test-btn" disabled
                    style="padding: 10px 24px; font-size: 14px; white-space: nowrap; background: #fff; color: #0071e3; border: 1.5px solid #0071e3; border-radius: 8px; cursor: pointer;">
                    {{ __('ui.test_pipeline') }}
                </button>
                @endif
                <span id="col-count-msg" style="font-size: 13px; color: #5f6368; white-space: nowrap;">{{ __('ui.select_one_column') }}</span>
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

        // Generate dataset and column descriptions using LLM via AJAX
        async function generateDescriptions() {
            const btn = document.getElementById('btn-generate-descriptions');
            const status = document.getElementById('desc-generate-status');
            const modelId = document.getElementById('desc-llm-model').value;
            const token = document.querySelector('meta[name="csrf-token"]')?.content
                       || document.querySelector('input[name="_token"]')?.value;

            btn.disabled = true;
            btn.style.opacity = '0.5';
            status.textContent = '{{ __("ui.generating") }}...';

            try {
                const resp = await fetch('{{ route("dataset.generate-descriptions", $dataset) }}', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token },
                    body: JSON.stringify({ model_id: modelId }),
                });
                const data = await resp.json();

                if (data.error) {
                    status.textContent = data.error;
                    return;
                }

                // Fill dataset description
                if (data.dataset_description) {
                    document.querySelector('textarea[name="dataset_description"]').value = data.dataset_description;
                }

                // Fill column descriptions
                if (data.column_descriptions) {
                    for (const [col, desc] of Object.entries(data.column_descriptions)) {
                        const input = document.querySelector(`input[name="column_descriptions[${col}]"]`);
                        if (input) input.value = desc;
                    }
                }

                status.textContent = '✓ {{ __("ui.generated") }}';
                setTimeout(() => { status.textContent = ''; }, 3000);
            } catch (e) {
                status.textContent = 'Error: ' + e.message;
            } finally {
                btn.disabled = false;
                btn.style.opacity = '1';
            }
        }

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
                        + `<input type="text" name="column_descriptions[${col}]" value="" style="padding:5px 8px;border:1px solid #d2d2d7;border-radius:6px;font-size:12px;" placeholder="{{ __('ui.column_description_placeholder') }}">`
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
                alert('{{ __("ui.reencode_failed") }}: ' + err.message);
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
                    <span class="handle" title="{{ __('ui.drag_to_reorder') }}">⠿</span>
                    <span class="col-index">#${col.index + 1}</span>
                    <span class="col-name">${allColumns[col.index]}</span>
                    <input type="text" class="col-label-input" value="${col.label}"
                        onchange="updateLabel(${col.index}, this.value)" placeholder="{{ __('ui.label') }}">
                    <button type="button" class="remove-btn" onclick="removeColumn(${col.index})" title="{{ __('ui.remove') }}">×</button>
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
                ? '{{ __("ui.drag_columns_here") }}'
                : '{{ __("ui.drop_to_add") }}';
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
                ? '{{ __("ui.select_one_column") }}'
                : `${selectedColumns.length} {{ __("ui.columns_selected") }}`;

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
                box.innerHTML = '<span style="color: #5f6368;">{{ __("ui.preview_select_columns") }}</span>';
                return;
            }
            const hasHeader = document.getElementById('has-header').value === '1';
            let headers = hasHeader ? allColumns : allColumns.map((_, i) => `{{ __("ui.column_fallback") }} ${i + 1}`);
            let html = '';
            previewRows.forEach((row, ri) => {
                html += `<div class="preview-row"><span style="color: #5f6368; font-size: 11px;">${ri + 1}</span>\n`;
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
            question: { _llm: '{{ __("ui.km_hint_question_llm") }}', _none: '', _col: '{{ __("ui.km_hint_col_direct_question") }}' },
            symptoms: { _llm: '{{ __("ui.km_hint_symptoms_llm") }}', _none: '', _col: '{{ __("ui.km_hint_col_direct") }}' },
            root_cause: { _llm: '{{ __("ui.km_hint_root_cause_llm") }}', _none: '', _col: '{{ __("ui.km_hint_col_direct") }}' },
            resolution: { _llm: '{{ __("ui.km_hint_resolution_llm") }}', _none: '', _col: '{{ __("ui.km_hint_col_direct_resolution") }}' },
            primary_filter: { _llm: '{{ __("ui.km_hint_primary_filter_llm") }}', _none: '{{ __("ui.km_hint_field_empty") }}', _col: '{{ __("ui.km_hint_col_direct") }}' },
            category: { _llm: '{{ __("ui.km_hint_category_llm") }}', _none: '{{ __("ui.km_hint_field_empty") }}', _col: '{{ __("ui.km_hint_col_direct") }}' },
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

        // Multi-clustering config management: add, remove, and sync hidden inputs

        /** Toggle parameter visibility within a clustering config row */
        function updateConfigParams(selectEl) {
            const row = selectEl.closest('.clustering-config-row');
            ['hdbscan', 'kmeans', 'agglomerative', 'leiden'].forEach(m => {
                const el = row.querySelector('.cc-params-' + m);
                if (el) el.style.display = (selectEl.value === m) ? '' : 'none';
            });
        }

        /** Add a new clustering config row by cloning the template.
         *  Copies method and parameter values from the last existing row
         *  so users can quickly create variations with minor tweaks. */
        function addClusteringConfig() {
            const list = document.getElementById('clustering-config-list');
            if (list.children.length >= 8) return; // Maximum 8 configurations
            const tmpl = document.getElementById('clustering-config-template');
            const clone = tmpl.content.cloneNode(true);
            const newRow = clone.querySelector('.clustering-config-row');

            // Copy method and parameters from the last existing row
            const existingRows = list.querySelectorAll('.clustering-config-row');
            if (existingRows.length > 0) {
                const lastRow = existingRows[existingRows.length - 1];
                const lastMethod = lastRow.querySelector('.cc-method').value;
                newRow.querySelector('.cc-method').value = lastMethod;

                // Copy all parameter input values from the last row
                const paramInputs = {
                    '.cc-leiden-neighbors': '.cc-leiden-neighbors',
                    '.cc-leiden-resolution': '.cc-leiden-resolution',
                    '.cc-hdbscan-min-cluster': '.cc-hdbscan-min-cluster',
                    '.cc-hdbscan-min-samples': '.cc-hdbscan-min-samples',
                    '.cc-kmeans-n': '.cc-kmeans-n',
                    '.cc-agg-n': '.cc-agg-n',
                    '.cc-agg-linkage': '.cc-agg-linkage',
                };
                for (const [srcSel, dstSel] of Object.entries(paramInputs)) {
                    const srcEl = lastRow.querySelector(srcSel);
                    const dstEl = newRow.querySelector(dstSel);
                    if (srcEl && dstEl) dstEl.value = srcEl.value;
                }

                // Toggle parameter visibility to match the copied method
                updateConfigParams(newRow.querySelector('.cc-method'));
            }

            list.appendChild(clone);
            renumberConfigs();
            document.getElementById('add-clustering-config').style.display =
                list.children.length >= 8 ? 'none' : '';
        }

        /** Remove a clustering config row */
        function removeClusteringConfig(btn) {
            const row = btn.closest('.clustering-config-row');
            const list = document.getElementById('clustering-config-list');
            // Keep at least one configuration
            if (list.children.length <= 1) return;
            row.remove();
            renumberConfigs();
            document.getElementById('add-clustering-config').style.display = '';
        }

        /** Renumber config rows and sync form input names with array indices */
        function renumberConfigs() {
            const list = document.getElementById('clustering-config-list');
            list.querySelectorAll('.clustering-config-row').forEach((row, i) => {
                row.querySelector('.config-number').textContent = 'Pattern #' + (i + 1);
                // Hide remove button if only one row remains
                const removeBtn = row.querySelector('.cc-remove');
                if (removeBtn) removeBtn.style.display = list.children.length <= 1 ? 'none' : '';
            });
        }

        /**
         * Before form submission, serialize all clustering config rows into
         * hidden inputs with array naming: clustering_configs[0][method], etc.
         */
        function syncClusteringConfigInputs() {
            // Remove previously generated hidden inputs
            document.querySelectorAll('.cc-hidden-input').forEach(el => el.remove());
            const container = document.getElementById('hidden-inputs');
            const rows = document.querySelectorAll('.clustering-config-row');
            rows.forEach((row, i) => {
                const method = row.querySelector('.cc-method').value;
                addHidden(container, `clustering_configs[${i}][method]`, method);
                // Add method-specific params
                if (method === 'hdbscan') {
                    addHidden(container, `clustering_configs[${i}][hdbscan_min_cluster_size]`, row.querySelector('.cc-hdbscan-min-cluster').value);
                    addHidden(container, `clustering_configs[${i}][hdbscan_min_samples]`, row.querySelector('.cc-hdbscan-min-samples').value);
                } else if (method === 'kmeans') {
                    addHidden(container, `clustering_configs[${i}][kmeans_n_clusters]`, row.querySelector('.cc-kmeans-n').value);
                } else if (method === 'agglomerative') {
                    addHidden(container, `clustering_configs[${i}][agglomerative_n_clusters]`, row.querySelector('.cc-agg-n').value);
                    addHidden(container, `clustering_configs[${i}][agglomerative_linkage]`, row.querySelector('.cc-agg-linkage').value);
                } else if (method === 'leiden') {
                    addHidden(container, `clustering_configs[${i}][leiden_n_neighbors]`, row.querySelector('.cc-leiden-neighbors').value);
                    addHidden(container, `clustering_configs[${i}][leiden_resolution]`, row.querySelector('.cc-leiden-resolution').value);
                }
            });
        }

        function addHidden(container, name, value) {
            const inp = document.createElement('input');
            inp.type = 'hidden'; inp.name = name; inp.value = value;
            inp.className = 'cc-hidden-input';
            container.appendChild(inp);
        }

        // Initialize with one default config row
        addClusteringConfig();

        // Sync clustering config hidden inputs and prevent double-submit
        document.getElementById('config-form').addEventListener('submit', function() {
            syncClusteringConfigInputs();
            const startBtn = document.getElementById('start-btn');
            const testBtn = document.getElementById('test-btn');
            [startBtn, testBtn].forEach(btn => {
                if (btn) {
                    btn.disabled = true;
                    btn.style.opacity = '0.6';
                    btn.style.cursor = 'wait';
                }
            });
            // Show hourglass feedback on the clicked button (activeElement)
            const clicked = document.activeElement;
            if (clicked && (clicked.id === 'start-btn' || clicked.id === 'test-btn')) {
                clicked.innerHTML = '&#9203; ' + clicked.textContent.trim();
            }
        });

        renderSelected();
        setupAvailableDrag();
@endsection
