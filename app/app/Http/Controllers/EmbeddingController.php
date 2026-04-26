<?php

namespace App\Http\Controllers;

use App\Models\Dataset;
use App\Models\Embedding;
use App\Models\KnowledgeUnit;
use App\Models\LlmModel;
use App\Models\PipelineJob;
use App\Support\CsvDownload;
use App\Support\ParameterSearchScoring;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Controller for the embedding-centric main view.
 *
 * Sidebar hierarchy: Embedding (parent) → Clustering runs (children).
 * Main area shows either a clustering comparison table (compare mode)
 * or knowledge units for a specific pipeline job.
 */
class EmbeddingController extends Controller
{
    /**
     * Main workspace view — sidebar + KU list or clustering comparison.
     *
     * Query params:
     *   ?compare=1  — show clustering comparison table for the embedding
     *   ?job={id}   — show KUs filtered to a specific pipeline job
     *   (none)      — show KUs from the latest completed job (backward compat)
     */
    public function index(Request $request, ?int $embeddingId = null)
    {
        // System admins have no workspace — redirect to admin dashboard
        if (auth()->user()->isSystemAdmin()) {
            return redirect()->route('admin.index');
        }

        $workspaceId = auth()->user()->workspace_id;

        // Embeddings with their completed pipeline jobs for sidebar tree.
        // Each embedding is a parent node; completed jobs are child nodes
        // showing clustering method, params, cluster count, and silhouette score.
        $sidebarEmbeddings = Embedding::where('workspace_id', $workspaceId)
            ->with(['dataset:id,name', 'pipelineJobs' => function ($jobQuery) {
                // Only show completed clustering/full-pipeline jobs in the sidebar.
                // Exclude parameter_search jobs so they don't leak into the
                // sidebar as ghost clustering runs:
                //   - start_step='parameter_search' is the current workspace
                //     code path (EmbeddingController::parameterSearch).
                //   - The post_embedding_action JSON guard handles legacy
                //     wizard-button jobs (the configure-screen flow has
                //     since been removed) so old rows don't reappear.
                $jobQuery->where('status', 'completed')
                    ->where('start_step', '!=', 'parameter_search')
                    ->where(function ($q) {
                        // JSON column: legacy rows either lack the key or
                        // have it explicitly. Null comparison on JSON
                        // extract needs the OR-whereNull guard because
                        // the != operator drops NULL rows.
                        $q->whereNull('pipeline_config_snapshot_json->post_embedding_action')
                          ->orWhere('pipeline_config_snapshot_json->post_embedding_action', '!=', 'parameter_search');
                    })
                    ->orderByDesc('created_at');
            }])
            ->withCount('knowledgeUnits')
            ->orderByDesc('created_at')
            ->get();

        // Datasets with data but no embeddings yet — show in sidebar so users
        // can navigate to the configure page and run the pipeline.
        $embeddedDatasetIds = $sidebarEmbeddings->pluck('dataset_id')->unique()->toArray();
        $pendingDatasets = Dataset::where('workspace_id', $workspaceId)
            ->where('row_count', '>', 0)
            ->whereNotIn('id', $embeddedDatasetIds)
            ->orderByDesc('created_at')
            ->get();

        // Select current embedding: explicit, or first available
        $current = null;
        $knowledgeUnits = collect();

        if ($embeddingId) {
            $current = Embedding::where('workspace_id', $workspaceId)
                ->with('dataset:id,name')
                ->find($embeddingId);
        }
        if (!$current && $sidebarEmbeddings->isNotEmpty()) {
            // Reuse the sidebar instance (already has dataset loaded)
            $current = $sidebarEmbeddings->first();
        }

        // Pipeline data (for integrated pipeline section)
        $pipelineView = $request->query('pipeline');
        $pipelineFilter = $request->query('pf', 'all');

        // Determine display mode: compare, specific job, pipeline, or default.
        // Pipeline and embedding selections are mutually exclusive.
        $compareMode = (bool) $request->query('compare');
        $currentJobId = $request->query('job');
        $clusteringRuns = collect();
        $embeddingJob = null;

        if ($pipelineView) {
            // Pipeline section overrides everything else
            $current = null;
            $knowledgeUnits = collect();
            $compareMode = false;
        } elseif ($current && $compareMode) {
            // Build clustering comparison data from all completed jobs
            $clusteringRuns = $this->buildClusteringRuns($current->id);
        } elseif ($current && $currentJobId) {
            // Load KUs filtered to a specific pipeline job
            $embeddingJob = PipelineJob::where('embedding_id', $current->id)
                ->where('id', $currentJobId)
                ->first();

            if ($embeddingJob && $embeddingJob->status === 'completed') {
                $knowledgeUnits = KnowledgeUnit::where('embedding_id', $current->id)
                    ->where('pipeline_job_id', $currentJobId)
                    ->orderByDesc('row_count')
                    ->get();
            }
        } elseif ($current) {
            // Default: load KUs from the latest completed job
            $embeddingJob = PipelineJob::where('embedding_id', $current->id)
                ->orderByDesc('created_at')
                ->first();

            if ($embeddingJob && $embeddingJob->status === 'completed') {
                $knowledgeUnits = KnowledgeUnit::where('embedding_id', $current->id)
                    ->orderByDesc('row_count')
                    ->get();
            }
        }

        $allJobs = PipelineJob::with('dataset:id,name')
            ->where('workspace_id', $workspaceId)
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();

        // Compute stats before any filtering (use filter() to avoid mutating)
        $jobStats = [
            'total' => $allJobs->count(),
            'completed' => $allJobs->filter(fn($job) => $job->status === 'completed')->count(),
            'processing' => $allJobs->filter(fn($job) => !in_array($job->status, ['completed', 'failed', 'cancelled']))->count(),
            'failed' => $allJobs->filter(fn($job) => in_array($job->status, ['failed', 'cancelled']))->count(),
        ];

        $filteredJobs = match ($pipelineFilter) {
            'completed' => $allJobs->filter(fn($job) => $job->status === 'completed'),
            'processing' => $allJobs->filter(fn($job) => !in_array($job->status, ['completed', 'failed', 'cancelled'])),
            'failed' => $allJobs->filter(fn($job) => in_array($job->status, ['failed', 'cancelled'])),
            default => $allJobs,
        };

        $llmModels = LlmModel::where('workspace_id', $workspaceId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        // Dataset-level KU count for the selected embedding's parent dataset.
        // Drives the "Delete dataset" button visibility in the detail view —
        // deletion is only permitted when no KUs exist that are still tied to
        // a live pipeline_job. Orphan KUs (pipeline_job_id IS NULL, left over
        // from a previously-deleted job) are ignored here to match the server
        // guard in DatasetWizardController::destroy — they would otherwise
        // silently lock the button forever after jobs are cleaned up.
        $currentDatasetHasKus = false;
        if ($current && $current->dataset_id) {
            $currentDatasetHasKus = KnowledgeUnit::where('dataset_id', $current->dataset_id)
                ->whereNotNull('pipeline_job_id')
                ->exists();
        }

        return view('workspace.index', [
            'sidebarEmbeddings' => $sidebarEmbeddings,
            'pendingDatasets' => $pendingDatasets,
            'current' => $current,
            'knowledgeUnits' => $knowledgeUnits,
            'embeddingJob' => $embeddingJob,
            'compareMode' => $compareMode,
            'clusteringRuns' => $clusteringRuns,
            'currentJobId' => $currentJobId,
            'pipelineView' => $pipelineView,
            'pipelineFilter' => $pipelineFilter,
            'jobs' => $filteredJobs,
            'jobStats' => $jobStats,
            'llmModels' => $llmModels,
            'currentDatasetHasKus' => $currentDatasetHasKus,
        ]);
    }

    /**
     * Build a sorted collection of clustering run summaries for comparison.
     *
     * Extracts clustering method, parameters, cluster count, silhouette score,
     * and noise count from each completed pipeline job's step_outputs_json.
     * Results are sorted by silhouette score descending so the best run appears first.
     */
    private function buildClusteringRuns(int $embeddingId): \Illuminate\Support\Collection
    {
        return PipelineJob::where('embedding_id', $embeddingId)
            ->where('status', 'completed')
            // Exclude parameter_search-style jobs from the comparison table.
            // They carry no clustering output, so buildClusteringRuns would
            // render them as "UNKNOWN method" rows — pure noise for the user.
            // Mirror the sidebar filter above (legacy + wizard variants).
            ->where('start_step', '!=', 'parameter_search')
            ->where(function ($q) {
                $q->whereNull('pipeline_config_snapshot_json->post_embedding_action')
                  ->orWhere('pipeline_config_snapshot_json->post_embedding_action', '!=', 'parameter_search');
            })
            ->orderByDesc('created_at')
            ->get()
            ->map(function ($job) {
                $cl = $job->step_outputs_json['clustering'] ?? [];
                return (object) [
                    'job_id'            => $job->id,
                    'clustering_method' => $cl['clustering_method'] ?? 'unknown',
                    'clustering_params' => $cl['clustering_params'] ?? [],
                    'n_clusters'        => $cl['n_clusters'] ?? null,
                    'silhouette_score'  => $cl['silhouette_score'] ?? null,
                    'n_noise'           => $cl['n_noise'] ?? null,
                    'ku_count'          => $job->knowledgeUnits()->count(),
                    'created_at'        => $job->created_at,
                ];
            })
            ->sortByDesc('silhouette_score')
            ->values();
    }

    /**
     * Dispatch a clustering-only pipeline job for an existing embedding.
     *
     * Reuses the embedding vectors from the latest completed job and runs
     * clustering → cluster_analysis → KU generation with new parameters.
     * This lets users compare different clustering approaches without
     * re-computing embeddings.
     */
    public function recluster(Request $request, int $embeddingId)
    {
        $request->validate([
            'clustering_method' => 'required|in:hdbscan,kmeans,agglomerative,leiden',
        ]);

        $workspaceId = auth()->user()->workspace_id;
        $embedding = Embedding::where('workspace_id', $workspaceId)->findOrFail($embeddingId);

        // Find the original full-pipeline job for this embedding (start_step='preprocess')
        // to get the embedding S3 output path. Clustering-only jobs don't have it.
        $sourceJob = PipelineJob::where('embedding_id', $embeddingId)
            ->where('status', 'completed')
            ->where('start_step', 'preprocess')
            ->orderByDesc('created_at')
            ->first();

        // Fallback: if no full-pipeline job found, walk the source_job_id chain
        if (!$sourceJob) {
            $sourceJob = PipelineJob::where('embedding_id', $embeddingId)
                ->where('status', 'completed')
                ->orderByDesc('created_at')
                ->firstOrFail();
        }

        // Clone the source job's config and override clustering settings
        $config = $sourceJob->pipeline_config_snapshot_json ?? [];
        $config['clustering_method'] = $request->input('clustering_method');
        $config['clustering_params'] = array_filter([
            'hdbscan_min_cluster_size' => $request->input('hdbscan_min_cluster_size'),
            'hdbscan_min_samples' => $request->input('hdbscan_min_samples'),
            'kmeans_n_clusters' => $request->input('kmeans_n_clusters'),
            'agglomerative_n_clusters' => $request->input('agglomerative_n_clusters'),
            'agglomerative_linkage' => $request->input('agglomerative_linkage'),
            'leiden_n_neighbors' => $request->input('leiden_n_neighbors'),
            'leiden_resolution' => $request->input('leiden_resolution'),
        ], fn($v) => $v !== null && $v !== '');
        $config['remove_language_bias'] = $request->boolean('remove_language_bias', true);

        // Check if a pipeline is already running
        $hasRunningPipeline = PipelineJob::where('workspace_id', $workspaceId)
            ->whereIn('status', ['submitted', 'processing', 'preprocess', 'embedding', 'clustering', 'cluster_analysis', 'knowledge_unit_generation'])
            ->exists();

        // Create a clustering-only job referencing the source job's embeddings.
        // Set embedding_id so the sidebar shows this job under the correct embedding.
        $job = PipelineJob::create([
            'workspace_id' => $workspaceId,
            'dataset_id' => $embedding->dataset_id,
            'embedding_id' => $embeddingId,
            'start_step' => 'clustering',
            'source_job_id' => $sourceJob->id,
            'status' => $hasRunningPipeline ? 'queued' : 'submitted',
            'progress' => 0,
            'pipeline_config_snapshot_json' => $config,
        ]);

        // Dispatch to SQS if not queued
        if (!$hasRunningPipeline) {
            $embeddingS3Path = $sourceJob->step_outputs_json['embedding']['output_s3_path'] ?? null;

            if ($embeddingS3Path) {
                // Inject embedding_id into config so the clustering step
                // can link new clusters to the right embedding parent.
                $dispatchConfig = $config;
                $dispatchConfig['embedding_id'] = $sourceJob->embedding_id ?? $embeddingId;

                \App\Services\PipelineJobService::dispatch(
                    job: $job,
                    step: 'clustering',
                    configOverride: $dispatchConfig,
                    inputS3Path: $embeddingS3Path,
                );
            }
        }

        $statusMsg = $hasRunningPipeline
            ? "Clustering job #{$job->id} queued."
            : "Clustering job #{$job->id} dispatched.";

        return redirect()
            ->route('workspace.embedding', ['embeddingId' => $embeddingId])
            ->with('success', $statusMsg)
            ->withInput(['compare' => '1']);
    }

    /**
     * Launch a parameter search job: samples embedding vectors and sweeps
     * across multiple clustering methods and parameter ranges.
     *
     * Results are stored in step_outputs_json and retrieved via
     * parameterSearchResults() for chart display.
     */
    public function parameterSearch(int $embeddingId)
    {
        $workspaceId = auth()->user()->workspace_id;
        $embedding = Embedding::where('workspace_id', $workspaceId)->findOrFail($embeddingId);

        // Find the original full-pipeline job for embedding S3 path
        $sourceJob = PipelineJob::where('embedding_id', $embeddingId)
            ->where('status', 'completed')
            ->where('start_step', 'preprocess')
            ->orderByDesc('created_at')
            ->first();
        if (!$sourceJob) {
            $sourceJob = PipelineJob::where('embedding_id', $embeddingId)
                ->where('status', 'completed')
                ->orderByDesc('created_at')
                ->firstOrFail();
        }

        // Build minimal config (no clustering-specific params needed — the sweep generates them)
        $config = $sourceJob->pipeline_config_snapshot_json ?? [];
        $config['embedding_id'] = $embeddingId;

        // Create a parameter_search job (lightweight, no chaining)
        $job = PipelineJob::create([
            'workspace_id' => $workspaceId,
            'dataset_id' => $embedding->dataset_id,
            'embedding_id' => $embeddingId,
            'start_step' => 'parameter_search',
            'source_job_id' => $sourceJob->id,
            'status' => 'submitted',
            'progress' => 0,
            'pipeline_config_snapshot_json' => $config,
        ]);

        // Resolve the embedding S3 path (walk source chain if needed)
        $embeddingS3Path = null;
        $currentSource = $sourceJob;
        for ($i = 0; $i < 5 && $currentSource; $i++) {
            $embeddingS3Path = $currentSource->step_outputs_json['embedding']['output_s3_path'] ?? null;
            if ($embeddingS3Path) break;
            $currentSource = $currentSource->source_job_id
                ? PipelineJob::find($currentSource->source_job_id)
                : null;
        }

        // Dispatch to SQS via the shared service. We only enqueue if the
        // embedding output path resolved — without it the worker has
        // nothing to sweep over, so the job will sit in 'submitted'
        // until manually retried.
        if ($embeddingS3Path) {
            \App\Services\PipelineJobService::dispatch(
                job: $job,
                step: 'parameter_search',
                configOverride: $config,
                inputS3Path: $embeddingS3Path,
            );
        }

        return redirect()
            ->route('workspace.embedding', ['embeddingId' => $embeddingId, 'compare' => 1])
            ->with('success', __('ui.parameter_search_started'));
    }

    /**
     * Return parameter search results as JSON for AJAX polling.
     */
    public function parameterSearchResults(int $embeddingId)
    {
        $workspaceId = auth()->user()->workspace_id;

        // Find the latest parameter_search job for this embedding
        $job = PipelineJob::where('embedding_id', $embeddingId)
            ->where('workspace_id', $workspaceId)
            ->where('start_step', 'parameter_search')
            ->orderByDesc('created_at')
            ->first();

        if (!$job) {
            return response()->json(['status' => 'not_found']);
        }

        $results = $job->step_outputs_json['parameter_search'] ?? null;

        return response()->json([
            'status' => $job->status,
            'progress' => $job->progress,
            'results' => $results,
        ]);
    }

    /**
     * Render the full parameter-search HTML report.
     *
     * Drop-in replacement for the previous client-side PDF export. The page
     * is print-friendly (the user prints to PDF from the browser when they
     * need a deliverable) and self-contained: chart, search conditions,
     * selected configuration, ranked accepted candidates with target-aware
     * scores, rejected candidate tables, per-method digest, and the
     * Bedrock-generated executive advisory section.
     *
     * Pipes raw `step_outputs_json` from the latest parameter_search job
     * into a Blade template; falls back to a "no results" page when the
     * sweep has not been run for this embedding yet.
     */
    public function parameterSearchReport(int $embeddingId): View
    {
        $workspaceId = auth()->user()->workspace_id;

        $embedding = Embedding::where('workspace_id', $workspaceId)
            ->with('dataset:id,name')
            ->findOrFail($embeddingId);

        // Latest parameter_search job for this embedding. Older jobs are
        // ignored — re-running the sweep replaces them anyway.
        $job = PipelineJob::where('embedding_id', $embeddingId)
            ->where('workspace_id', $workspaceId)
            ->where('start_step', 'parameter_search')
            ->orderByDesc('created_at')
            ->first();

        $payload = $job?->step_outputs_json['parameter_search'] ?? null;
        if (!$payload || empty($payload['results'])) {
            return view('workspace.parameter_search_report', [
                'embedding' => $embedding,
                'job' => $job,
                'payload' => null,
            ]);
        }

        $sampleSize = max((int) ($payload['sample_size'] ?? 1), 1);
        $totalRows = (int) ($payload['total_rows'] ?? $sampleSize);
        $target = (string) ($payload['target'] ?? ParameterSearchScoring::DEFAULT_TARGET);
        $noiseThreshold = (float) ($payload['noise_ratio_threshold'] ?? 0.5);

        // Normalise every trial: backfill missing fields with derived values
        // so the Blade template can assume a uniform shape regardless of
        // whether the job was created before or after the worker enrichment.
        $trials = collect($payload['results'])->map(function (array $trial) use ($sampleSize, $noiseThreshold) {
            $sil = $trial['silhouette_score'] ?? null;
            $silFloat = is_numeric($sil) ? (float) $sil : null;
            $nClusters = (int) ($trial['n_clusters'] ?? 0);
            $nNoise = (int) ($trial['n_noise'] ?? 0);
            $maxShare = isset($trial['max_cluster_share'])
                ? (float) $trial['max_cluster_share']
                : 0.0;
            $noiseRatio = $sampleSize > 0 ? $nNoise / $sampleSize : 0.0;

            // Pre-computed scores from the worker (preferred); fall back to
            // recomputing in PHP for older jobs that lack the columns.
            $scoreFor = function (string $tgt) use ($trial, $silFloat, $nClusters, $maxShare) {
                $key = "score_{$tgt}";
                if (isset($trial[$key]) && is_numeric($trial[$key])) {
                    return (float) $trial[$key];
                }
                return ParameterSearchScoring::score($silFloat, $nClusters, $maxShare, $tgt);
            };

            $scoreFaq = $scoreFor('faq');
            $scoreChatbot = $scoreFor('chatbot');
            $scoreInsight = $scoreFor('insight');

            // Categorise each trial so the report can split Accepted /
            // Rejected (noise filter) / Rejected (degenerate) sections.
            if ($silFloat === null || $silFloat <= -1.0 || $nClusters < 2) {
                $status = 'degenerate';
            } elseif ($noiseRatio > $noiseThreshold) {
                $status = 'rejected_noise';
            } else {
                $status = 'accepted';
            }

            return array_merge($trial, [
                'silhouette_score' => $silFloat,
                'n_clusters' => $nClusters,
                'n_noise' => $nNoise,
                'max_cluster_share' => $maxShare,
                'noise_ratio' => $noiseRatio,
                'score_faq' => $scoreFaq,
                'score_chatbot' => $scoreChatbot,
                'score_insight' => $scoreInsight,
                'status' => $status,
            ]);
        });

        // Chart-rank: bar position in the silhouette chart (sorted desc).
        // Stamp the rank onto each trial directly — earlier the Blade did a
        // separate lookup keyed by (method, params), but params dictionaries
        // arrived in different orders depending on how each table sorted
        // them, so the lookup missed and "Chart #" was empty for every row.
        // Storing the rank on the trial dodges the key-equality problem.
        $silhouetteRanked = $trials->filter(fn($t) => $t['silhouette_score'] !== null)
            ->sortByDesc('silhouette_score')
            ->values();
        $silhouetteRanked = $silhouetteRanked->map(function ($trial, $idx) {
            $trial['chart_rank'] = $idx + 1;
            return $trial;
        });

        // Propagate chart_rank back onto every trial in the master collection
        // (Accepted/Rejected tables read from $trials, not from
        // $silhouetteRanked, so they need the rank attached too). The
        // (method, params) tuple is canonical because no two trials share it.
        $rankByKey = [];
        foreach ($silhouetteRanked as $trial) {
            $rankByKey[$this->trialKey($trial)] = $trial['chart_rank'];
        }
        $trials = $trials->map(function ($trial) use ($rankByKey) {
            $trial['chart_rank'] = $rankByKey[$this->trialKey($trial)] ?? null;
            return $trial;
        });

        $accepted = $trials->where('status', 'accepted')
            ->sortByDesc("score_{$target}")
            ->values();
        $rejectedNoise = $trials->where('status', 'rejected_noise')
            ->sortByDesc('silhouette_score')
            ->values();
        $rejectedDegenerate = $trials->where('status', 'degenerate')->values();

        $winner = $accepted->first();

        // Per-method digest tables (KMeans / HDBSCAN / Leiden), preserving
        // the original sweep order within each method group.
        $byMethod = $trials->groupBy('method')->map(fn($group) => $group->values());

        return view('workspace.parameter_search_report', [
            'embedding' => $embedding,
            'job' => $job,
            'payload' => $payload,
            'target' => $target,
            'sampleSize' => $sampleSize,
            'totalRows' => $totalRows,
            'configsTested' => (int) ($payload['configs_tested'] ?? $trials->count()),
            'noiseThreshold' => $noiseThreshold,
            'trials' => $trials,
            'silhouetteRanked' => $silhouetteRanked,
            'chartRanks' => $chartRanks,
            'accepted' => $accepted,
            'rejectedNoise' => $rejectedNoise,
            'rejectedDegenerate' => $rejectedDegenerate,
            'byMethod' => $byMethod,
            'winner' => $winner,
            'advisoryMd' => (string) ($payload['advisory_markdown'] ?? ''),
            'topClusters' => $payload['top_clusters'] ?? [],
            'advisorMeta' => $payload['advisor_meta'] ?? [],
        ]);
    }

    /**
     * Stable identity for a trial — used when building the chart-rank map
     * so the Accepted/Rejected tables can look up the bar position from
     * the same `(method, params)` tuple that the chart renders.
     */
    private function trialKey(array $trial): string
    {
        $params = $trial['params'] ?? [];
        if (is_array($params)) {
            ksort($params);
        }
        return json_encode([$trial['method'] ?? '', $params], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Dismiss (delete) the parameter search job for an embedding.
     */
    public function dismissParameterSearch(int $embeddingId)
    {
        $workspaceId = auth()->user()->workspace_id;

        PipelineJob::where('embedding_id', $embeddingId)
            ->where('workspace_id', $workspaceId)
            ->where('start_step', 'parameter_search')
            ->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * Show a single KU detail (edit, review, versions).
     */
    public function showKnowledgeUnit(int $embeddingId, int $kuId): View
    {
        $embedding = Embedding::where('workspace_id', auth()->user()->workspace_id)
            ->findOrFail($embeddingId);

        $knowledgeUnit = KnowledgeUnit::where('embedding_id', $embeddingId)
            ->findOrFail($kuId);

        $embeddings = Embedding::where('workspace_id', auth()->user()->workspace_id)
            ->with('dataset:id,name')
            ->orderByDesc('created_at')
            ->get();

        return view('workspace.ku_detail', [
            'embeddings' => $embeddings,
            'current' => $embedding,
            'ku' => $knowledgeUnit,
        ]);
    }

    /**
     * Bulk approve all draft KUs for an embedding.
     */
    public function bulkApprove(int $embeddingId)
    {
        $embedding = Embedding::where('workspace_id', auth()->user()->workspace_id)
            ->findOrFail($embeddingId);

        $updated = KnowledgeUnit::where('embedding_id', $embeddingId)
            ->where('review_status', 'draft')
            ->update([
                'review_status' => 'approved',
                'edited_by_user_id' => auth()->id(),
                'edited_at' => now(),
                'updated_at' => now(),
            ]);

        return redirect()->route('workspace.index', ['embeddingId' => $embeddingId])
            ->with('success', "{$updated} KUs approved.");
    }

    /**
     * Bulk update review_status for knowledge units.
     *
     * Accepts ku_ids as an array of IDs or the string 'all' to target
     * every KU in the embedding. Status is limited to approved/draft.
     */
    public function bulkUpdateStatus(Request $request, int $embeddingId)
    {
        $request->validate([
            'new_status' => 'required|in:draft,approved',
        ]);

        $embedding = Embedding::where('workspace_id', auth()->user()->workspace_id)
            ->findOrFail($embeddingId);

        // Build query: 'all' targets every KU, otherwise specific IDs
        $query = KnowledgeUnit::where('embedding_id', $embeddingId);
        $kuIds = $request->input('ku_ids');
        if ($kuIds !== 'all') {
            $ids = is_array($kuIds) ? $kuIds : explode(',', $kuIds);
            $query->whereIn('id', $ids);
        }

        $updated = $query->update([
            'review_status' => $request->input('new_status'),
            'edited_by_user_id' => auth()->id(),
            'edited_at' => now(),
            'updated_at' => now(),
        ]);

        $label = $request->input('new_status') === 'approved' ? __('ui.approved') : __('ui.excluded');

        return redirect()->route('workspace.embedding', ['embeddingId' => $embeddingId])
            ->with('success', "{$updated} KU(s) → {$label}");
    }

    /**
     * Rename an embedding.
     */
    public function rename(Request $request, int $embeddingId)
    {
        $request->validate(['name' => 'required|string|max:255']);

        $embedding = Embedding::where('workspace_id', auth()->user()->workspace_id)
            ->findOrFail($embeddingId);

        $old = $embedding->name;
        $newName = $request->input('name');
        $embedding->update(['name' => $newName]);

        // Keep the parent dataset name in sync so the sidebar shows
        // a consistent name (UI treats dataset and embedding as one entity)
        if ($embedding->dataset_id) {
            Dataset::where('id', $embedding->dataset_id)->update(['name' => $newName]);
        }

        // Preserve the current view mode (compare or job) when redirecting back
        $params = ['embeddingId' => $embeddingId];
        if ($request->input('compare')) $params['compare'] = 1;
        if ($request->input('job')) $params['job'] = $request->input('job');

        return redirect()->route('workspace.embedding', $params)
            ->with('success', "Renamed: {$old} → {$embedding->name}");
    }

    /**
     * Export approved KUs for an embedding as CSV or JSON.
     * Format is determined by the ?format= query parameter (default: json).
     *
     * Scope:
     *   - ?job={id}  → only KUs produced by that specific clustering run
     *                  (matches the YYYYMMDD-HHMM header the user selected)
     *   - no ?job    → backward-compat: all approved KUs under the embedding
     *                  (aggregated across every clustering run)
     *
     * The job-scoped mode exists because the workspace UI shows one clustering
     * run at a time, and exporting "this one" should not silently include
     * sibling runs under the same embedding.
     */
    public function export(Request $request, int $embeddingId)
    {
        $workspaceId = auth()->user()->workspace_id;
        $embedding = Embedding::where('workspace_id', $workspaceId)
            ->findOrFail($embeddingId);

        $columns = [
            'id', 'topic', 'intent', 'summary', 'keywords_json',
            'question', 'symptoms', 'root_cause', 'resolution_summary',
            'primary_filter', 'category', 'language', 'row_count', 'review_status',
        ];

        $query = KnowledgeUnit::where('embedding_id', $embeddingId)
            ->where('review_status', 'approved');

        // When the caller specifies a clustering run, narrow the result to
        // only KUs from that run. Also verify the job belongs to this
        // embedding so callers can't read across embeddings via the URL.
        $jobId = $request->query('job');
        $filenameSuffix = '';
        if ($jobId) {
            $job = PipelineJob::where('id', $jobId)
                ->where('workspace_id', $workspaceId)
                ->where('embedding_id', $embeddingId)
                ->firstOrFail();
            $query->where('pipeline_job_id', $job->id);
            // Tag the filename with the run timestamp so multiple exports
            // from the same embedding are distinguishable on disk.
            $filenameSuffix = '_' . $job->created_at->format('Ymd-Hi');
        }

        $kus = $query->orderBy('topic')->get($columns);

        $format = $request->query('format', 'json');
        $baseFilename = Str::slug($embedding->name) . '_clusters' . $filenameSuffix;

        if ($format === 'csv') {
            return $this->exportAsCsv($kus, $columns, $baseFilename);
        }

        return $this->exportAsJson($kus, $embedding->name, $baseFilename);
    }

    /**
     * Build a CSV download response from the given KU collection.
     * Outputs UTF-8 with BOM so Excel opens it correctly.
     */
    private function exportAsCsv($kus, array $columns, string $baseFilename)
    {
        // Flatten KU records into ordered scalar rows. Two column-specific
        // transforms:
        //   - id is replaced by a 1-based row sequence. The KnowledgeUnit
        //     primary key is internal bookkeeping and leaks DB sequence
        //     state into customer-facing exports — a simple "row number"
        //     is what spreadsheet readers actually expect in column 1.
        //   - keywords_json is stored as a JSON array but should appear as a
        //     semicolon-separated cell so it round-trips through Excel.
        $rows = [];
        $rowNumber = 0;
        foreach ($kus as $ku) {
            $rowNumber++;
            $row = [];
            foreach ($columns as $col) {
                if ($col === 'id') {
                    $row[] = $rowNumber;
                    continue;
                }
                $value = $ku->{$col};
                if ($col === 'keywords_json' && is_array($value)) {
                    $value = implode('; ', $value);
                }
                $row[] = $value ?? '';
            }
            $rows[] = $row;
        }
        return CsvDownload::make($columns, $rows, $baseFilename);
    }

    /**
     * Build a JSON download response from the given KU collection.
     */
    private function exportAsJson($kus, string $embeddingName, string $baseFilename)
    {
        return response()->json([
            'embedding' => $embeddingName,
            'exported_at' => now()->toIso8601String(),
            'total' => $kus->count(),
            'knowledge_units' => $kus,
        ])->header('Content-Disposition', "attachment; filename=\"{$baseFilename}.json\"");
    }

    /**
     * Export original dataset rows with their assigned cluster name appended.
     *
     * Joins dataset_rows → cluster_memberships → clusters to produce a CSV
     * containing all original columns plus a "cluster_topic" column. Rows not
     * assigned to any cluster get an empty cluster_topic value.
     *
     * Scope:
     *   - ?job={id}  → cluster assignments from that specific clustering run
     *                  (matches the YYYYMMDD-HHMM header the user selected)
     *   - no ?job    → backward-compat: uses the most recent job on the
     *                  embedding. Legacy behaviour only; the UI now always
     *                  passes `job` for the currently-selected run.
     */
    public function exportWithClusters(Request $request, int $embeddingId)
    {
        $workspaceId = auth()->user()->workspace_id;
        $embedding = Embedding::where('workspace_id', $workspaceId)
            ->findOrFail($embeddingId);

        // Resolve the target pipeline job. Explicit job beats "latest" so the
        // downloaded file matches the run visible in the UI.
        $jobId = $request->query('job');
        $filenameSuffix = '';
        if ($jobId) {
            $job = PipelineJob::where('id', $jobId)
                ->where('workspace_id', $workspaceId)
                ->where('embedding_id', $embeddingId)
                ->firstOrFail();
            $resolvedJobId = $job->id;
            $filenameSuffix = '_' . $job->created_at->format('Ymd-Hi');
        } else {
            // Legacy path: fall back to the newest job on the embedding.
            $resolvedJobId = PipelineJob::where('workspace_id', $workspaceId)
                ->where('embedding_id', $embeddingId)
                ->orderByDesc('created_at')
                ->value('id');
            if (!$resolvedJobId) {
                return back()->with('error', 'No clustering run found for this embedding.');
            }
        }

        // Fetch original rows with the cluster topic name for the resolved job.
        // Using a parameterised job_id keeps the query deterministic (the old
        // ORDER BY ... LIMIT 1 subquery drifted whenever new runs were added).
        $rows = \DB::select("
            SELECT dr.row_no, dr.metadata_json, c.topic_name AS cluster_topic
            FROM dataset_rows dr
            LEFT JOIN cluster_memberships cm ON cm.dataset_row_id = dr.id
            LEFT JOIN clusters c ON c.id = cm.cluster_id
                AND c.pipeline_job_id = ?
            WHERE dr.dataset_id = ? AND dr.workspace_id = ?
            ORDER BY dr.row_no
        ", [$resolvedJobId, $embedding->dataset_id, $workspaceId]);

        if (empty($rows)) {
            return back()->with('error', 'No data rows found.');
        }

        // Determine CSV columns from the first row's metadata keys
        $firstMeta = json_decode($rows[0]->metadata_json, true) ?? [];
        $originalColumns = array_keys($firstMeta);
        $csvHeader = array_merge($originalColumns, ['cluster_topic']);

        // Project each row's metadata_json into ordered scalar values
        // matching $originalColumns, and append the joined cluster topic.
        $csvRows = [];
        foreach ($rows as $row) {
            $meta = json_decode($row->metadata_json, true) ?? [];
            $csvRow = [];
            foreach ($originalColumns as $col) {
                $csvRow[] = $meta[$col] ?? '';
            }
            $csvRow[] = $row->cluster_topic ?? '';
            $csvRows[] = $csvRow;
        }

        $filename = Str::slug($embedding->name) . '_rows_with_clusters' . $filenameSuffix;
        return CsvDownload::make($csvHeader, $csvRows, $filename);
    }

    /**
     * Delete an embedding and all its related data (KUs, clusters, etc.).
     */
    public function destroy(int $embeddingId)
    {
        $workspaceId = auth()->user()->workspace_id;
        $embedding = Embedding::where('workspace_id', $workspaceId)
            ->findOrFail($embeddingId);

        $name = $embedding->name;
        $datasetId = $embedding->dataset_id;

        // Find the next embedding to select after deletion
        // Get all embeddings for the same dataset, ordered
        $siblings = Embedding::where('workspace_id', $workspaceId)
            ->where('dataset_id', $datasetId)
            ->orderBy('created_at', 'desc')
            ->pluck('id')
            ->toArray();

        $currentIdx = array_search($embeddingId, $siblings);
        $nextId = null;

        // Select the next sibling to redirect to after deletion
        if ($currentIdx !== false && count($siblings) > 1) {
            // Try the next sibling, or fall back to the previous one
            if (isset($siblings[$currentIdx + 1])) {
                $nextId = $siblings[$currentIdx + 1];
            } elseif ($currentIdx > 0) {
                $nextId = $siblings[$currentIdx - 1];
            }
        }

        // Delete the embedding — cascade deletes handle pipeline jobs, KUs, and clusters
        // via ON DELETE CASCADE foreign keys on embedding_id.
        $embedding->delete();

        // Redirect to the next sibling embedding, or workspace root
        if ($nextId) {
            return redirect()->route('workspace.embedding', $nextId)
                ->with('success', "Deleted: {$name}");
        }

        return redirect()->route('workspace.index')
            ->with('success', "Deleted: {$name}");
    }

    /**
     * Delete all orphaned pipeline jobs (failed/submitted jobs with no matching dataset).
     */
    public function cleanupJobs(): \Illuminate\Http\RedirectResponse
    {
        $workspaceId = auth()->user()->workspace_id;

        $deleted = PipelineJob::where('workspace_id', $workspaceId)
            ->whereIn('status', ['failed', 'submitted'])
            ->delete();

        return redirect()->route('workspace.index', ['pipeline' => 'jobs', 'pf' => 'all'])
            ->with('success', "Cleaned up {$deleted} job(s).");
    }
}
