<?php

namespace App\Http\Controllers;

use App\Models\Cluster;
use App\Models\Dataset;
use App\Models\DatasetRow;
use App\Models\KnowledgeUnit;
use App\Models\LlmModel;
use App\Models\PipelineJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Dashboard controller for the Phase 0 management UI.
 *
 * Provides a simple web interface to monitor pipeline jobs,
 * dispatch test jobs, and verify the end-to-end pipeline.
 */
class DashboardController extends Controller
{
    /**
     * Display the dashboard with job list and statistics.
     */
    public function index(): View
    {
        $workspaceId = auth()->user()->workspace_id;
        $filter = request('filter', 'all');

        // Fetch all jobs for stats calculation
        $allJobs = PipelineJob::with('dataset:id,name')
            ->where('workspace_id', $workspaceId)
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();

        $stats = [
            'total' => $allJobs->count(),
            'completed' => $allJobs->where('status', 'completed')->count(),
            'processing' => $allJobs->whereNotIn('status', ['completed', 'failed', 'submitted'])->count(),
            'failed' => $allJobs->where('status', 'failed')->count(),
        ];

        // Apply the user's status filter to the job collection
        $jobs = match ($filter) {
            'completed' => $allJobs->where('status', 'completed'),
            'processing' => $allJobs->whereNotIn('status', ['completed', 'failed', 'submitted']),
            'failed' => $allJobs->where('status', 'failed'),
            default => $allJobs,
        };

        $datasets = Dataset::where('workspace_id', $workspaceId)
            ->where('row_count', '>', 0)
            ->get();

        $llmModels = LlmModel::where('workspace_id', $workspaceId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        return view('dashboard.index', compact('jobs', 'datasets', 'stats', 'llmModels', 'filter'));
    }

    /**
     * Show detailed results for a single pipeline job including cluster data.
     */
    public function show(PipelineJob $pipelineJob): View
    {
        $pipelineJob->load('dataset');

        // Load clusters with representative rows for this job
        $clusters = Cluster::where('pipeline_job_id', $pipelineJob->id)
            ->orderByDesc('row_count')
            ->get();

        // Load representative rows with their raw text
        $representatives = DB::table('cluster_representatives')
            ->join('clusters', 'cluster_representatives.cluster_id', '=', 'clusters.id')
            ->join('dataset_rows', 'cluster_representatives.dataset_row_id', '=', 'dataset_rows.id')
            ->where('clusters.pipeline_job_id', $pipelineJob->id)
            ->select(
                'cluster_representatives.cluster_id',
                'cluster_representatives.rank',
                'cluster_representatives.distance_to_centroid',
                'dataset_rows.raw_text',
            )
            ->orderBy('cluster_representatives.cluster_id')
            ->orderBy('cluster_representatives.rank')
            ->get()
            ->groupBy('cluster_id');

        return view('dashboard.show', [
            'job' => $pipelineJob,
            'clusters' => $clusters,
            'representatives' => $representatives,
        ]);
    }

    /**
     * Upload a CSV file to create a new dataset with rows.
     *
     * The first column of the CSV (or a user-specified column) is treated as
     * the raw_text for each row. A dataset record and its dataset_rows are
     * created in a single transaction.
     */
    public function uploadCsv(Request $request): RedirectResponse
    {
        $request->validate([
            'csv_file' => 'required|file|mimes:csv,txt|max:51200',
            'dataset_name' => 'required|string|max:255',
            'text_column' => 'nullable|string|max:100',
            'max_rows' => 'nullable|integer|min:1|max:100000',
        ]);

        $workspaceId = auth()->user()->workspace_id;
        $file = $request->file('csv_file');
        $textColumn = $request->input('text_column');
        $maxRows = $request->input('max_rows');

        // Read CSV into array
        $handle = fopen($file->getRealPath(), 'r');
        if (!$handle) {
            return redirect()->route('dashboard')->with('error', 'Failed to read CSV file.');
        }

        // Detect delimiter by inspecting the first line
        $firstLine = fgets($handle);
        rewind($handle);
        $delimiter = (substr_count($firstLine, "\t") > substr_count($firstLine, ',')) ? "\t" : ',';

        // Read header row
        $header = fgetcsv($handle, 0, $delimiter);
        if (!$header || count($header) === 0) {
            fclose($handle);
            return redirect()->route('dashboard')->with('error', 'CSV file has no header row.');
        }

        // Determine which column index to use for raw_text
        $textColIndex = 0;
        if ($textColumn) {
            $found = array_search($textColumn, $header);
            if ($found !== false) {
                $textColIndex = $found;
            } else {
                fclose($handle);
                $available = implode(', ', $header);
                return redirect()->route('dashboard')
                    ->with('error', "Column '{$textColumn}' not found. Available: {$available}");
            }
        }

        // Read rows
        $rows = [];
        $rowNo = 1;
        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $text = trim($row[$textColIndex] ?? '');
            if ($text === '') {
                continue;
            }
            $rows[] = [
                'row_no' => $rowNo,
                'raw_text' => $text,
                'metadata_json' => json_encode(array_combine($header, $row)),
            ];
            $rowNo++;
            if ($maxRows && $rowNo > $maxRows) {
                break;
            }
        }
        fclose($handle);

        if (empty($rows)) {
            return redirect()->route('dashboard')->with('error', 'CSV contains no data rows.');
        }

        // Create dataset + rows in transaction
        DB::beginTransaction();
        try {
            $dataset = Dataset::create([
                'workspace_id' => $workspaceId,
                'name' => $request->input('dataset_name'),
                'source_type' => 'csv',
                'original_filename' => $file->getClientOriginalName(),
                'row_count' => count($rows),
                'schema_json' => $header,
            ]);

            $now = now();
            $chunks = array_chunk($rows, 500);
            foreach ($chunks as $chunk) {
                $inserts = [];
                foreach ($chunk as $rowData) {
                    $inserts[] = [
                        'dataset_id' => $dataset->id,
                        'workspace_id' => $workspaceId,
                        'row_no' => $rowData['row_no'],
                        'raw_text' => $rowData['raw_text'],
                        'metadata_json' => $rowData['metadata_json'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                DatasetRow::insert($inserts);
            }

            DB::commit();

            return redirect()->route('dashboard')
                ->with('success', "Dataset '{$dataset->name}' created with " . count($rows) . " rows.");
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('CSV upload failed', ['error' => $e->getMessage()]);
            return redirect()->route('dashboard')
                ->with('error', 'Upload failed: ' . $e->getMessage());
        }
    }

    /**
     * Dispatch a pipeline job (preprocess step) to SQS.
     */
    public function dispatchPipeline(Request $request): RedirectResponse
    {
        $request->validate([
            'dataset_id' => 'required|exists:datasets,id',
            'llm_model_id' => 'nullable|string|max:100',
            'clustering_method' => 'nullable|in:hdbscan,kmeans,agglomerative,leiden',
        ]);

        $workspaceId = auth()->user()->workspace_id;

        // Check if a pipeline is already running in this workspace
        $runningStatuses = ['submitted', 'processing', 'preprocess', 'embedding', 'clustering', 'cluster_analysis', 'knowledge_unit_generation'];
        $hasRunningPipeline = PipelineJob::where('workspace_id', $workspaceId)
            ->whereIn('status', $runningStatuses)
            ->exists();

        // Resolve the default LLM model from the database registry
        $defaultModel = LlmModel::where('workspace_id', $workspaceId)
            ->where('is_default', true)
            ->first();
        $defaultLlmModel = $defaultModel?->model_id ?? 'jp.anthropic.claude-haiku-4-5-20251001-v1:0';

        // Build clustering hyperparameters based on the selected algorithm
        $clusteringMethod = $request->input('clustering_method', 'hdbscan');
        $clusteringParams = match ($clusteringMethod) {
            'hdbscan' => [
                'min_cluster_size' => (int) $request->input('hdbscan_min_cluster_size', 15),
                'min_samples' => (int) $request->input('hdbscan_min_samples', 5),
                'metric' => 'euclidean',
                'cluster_selection_method' => 'eom',
            ],
            'kmeans' => [
                'n_clusters' => (int) $request->input('kmeans_n_clusters', 10),
            ],
            'agglomerative' => [
                'n_clusters' => (int) $request->input('agglomerative_n_clusters', 10),
                'linkage' => $request->input('agglomerative_linkage', 'ward'),
            ],
            'leiden' => [
                'n_neighbors' => (int) $request->input('leiden_n_neighbors', 15),
                'resolution' => (float) $request->input('leiden_resolution', 1.0),
            ],
            default => [],
        };

        // Resolve dataset name for embedding record
        $dataset = Dataset::find($request->input('dataset_id'));
        $datasetName = $dataset ? $dataset->name : "Dataset {$request->input('dataset_id')}";

        $pipelineConfig = [
            'phase' => '2',
            'llm_model_id' => $request->input('llm_model_id', $defaultLlmModel),
            'embedding_model' => $request->input('embedding_model_id', 'amazon.titan-embed-text-v2:0'),
            'embedding_dimension' => (int) ($request->input('embedding_dimension', 1024)),
            'clustering_method' => $clusteringMethod,
            'clustering_params' => $clusteringParams,
            'remove_language_bias' => $request->has('remove_language_bias'),
            'dataset_name' => $datasetName,
        ];

        // If another pipeline is running, queue this job instead of dispatching
        if ($hasRunningPipeline) {
            $job = PipelineJob::create([
                'workspace_id' => $workspaceId,
                'dataset_id' => $request->input('dataset_id'),
                'status' => 'queued',
                'progress' => 0,
                'pipeline_config_snapshot_json' => $pipelineConfig,
            ]);

            return redirect()->route('dashboard')
                ->with('success', __('ui.pipeline_queued'));
        }

        // Create the job record for a full pipeline run
        $job = PipelineJob::create([
            'workspace_id' => $workspaceId,
            'dataset_id' => $request->input('dataset_id'),
            'status' => 'submitted',
            'progress' => 0,
            'pipeline_config_snapshot_json' => $pipelineConfig,
        ]);

        // Send first step (preprocess) to SQS via the shared dispatcher.
        try {
            \App\Services\PipelineJobService::dispatch($job, 'preprocess');

            return redirect()->route('dashboard')
                ->with('success', "Pipeline Job #{$job->id} dispatched (preprocess -> embedding -> clustering).");
        } catch (\Exception $e) {
            return redirect()->route('dashboard')
                ->with('error', "Job #{$job->id} created but dispatch failed: {$e->getMessage()}");
        }
    }

    /**
     * Cancel a running pipeline job by setting its status to 'failed'.
     */
    public function cancelPipeline(PipelineJob $pipelineJob): RedirectResponse
    {
        if (in_array($pipelineJob->status, ['completed', 'failed', 'cancelled'])) {
            return redirect()->back()->with('error', __('ui.job_not_cancellable'));
        }

        $pipelineJob->update([
            'status' => 'cancelled',
            'error_detail' => 'Cancelled by user',
        ]);

        return redirect()->back()->with('success', __('ui.job_cancelled'));
    }

    /**
     * Retry a stuck pipeline job by re-sending its SQS message.
     *
     * A job is considered stuck when it has status='submitted' but progress=0
     * for an extended period, usually caused by an SQS delivery failure.
     * This re-dispatches the appropriate step based on the job's start_step.
     */
    public function retryJob(PipelineJob $pipelineJob): RedirectResponse
    {
        // Only allow retry on submitted jobs with no progress
        if ($pipelineJob->status !== 'submitted' || $pipelineJob->progress > 0) {
            return redirect()->back()->with('error', __('ui.job_not_retryable'));
        }

        $config = $pipelineJob->pipeline_config_snapshot_json ?? [];
        $startStep = $pipelineJob->start_step ?? 'preprocess';
        $inputS3Path = null;

        // For clustering-only jobs, resolve the embedding S3 path from the source chain
        if ($startStep !== 'preprocess') {
            $sourceId = $pipelineJob->source_job_id;
            // Walk the source chain to find the full-pipeline job with embedding output
            for ($i = 0; $i < 5 && $sourceId; $i++) {
                $source = PipelineJob::find($sourceId);
                if (!$source) break;
                $embPath = $source->step_outputs_json['embedding']['output_s3_path'] ?? null;
                if ($embPath) {
                    $inputS3Path = $embPath;
                    $config['embedding_id'] = $source->embedding_id ?? $pipelineJob->embedding_id;
                    break;
                }
                $sourceId = $source->source_job_id;
            }

            if (!$inputS3Path) {
                return redirect()->back()->with('error', __('ui.retry_no_embedding'));
            }
        }

        // Send to SQS via the shared dispatcher.
        // Use $config (which may have a freshly-resolved embedding_id) rather
        // than the snapshot — the snapshot was captured at submit time and
        // may be missing fields that retry needs.
        \App\Services\PipelineJobService::dispatch(
            job: $pipelineJob,
            step: $startStep,
            configOverride: $config,
            inputS3Path: $inputS3Path,
        );

        return redirect()->back()->with('success', __('ui.job_retried'));
    }

    /**
     * Delete a completed/failed pipeline job and its cascade-deleted children
     * (clusters, KUs, etc.). Only allowed for non-running jobs.
     */
    public function destroyJob(PipelineJob $pipelineJob): RedirectResponse
    {
        // Guard: cannot delete jobs that are actively processing
        if (!in_array($pipelineJob->status, ['completed', 'failed', 'cancelled', 'queued'])) {
            return redirect()->back()->with('error', __('ui.cannot_delete_running'));
        }

        // Guard: cannot delete a source job that other jobs depend on.
        // Deleting a full-pipeline job would orphan clustering-only jobs
        // and could trigger cascade deletion of shared resources.
        $dependentCount = PipelineJob::where('source_job_id', $pipelineJob->id)->count();
        if ($dependentCount > 0) {
            return redirect()->back()->with('error', __('ui.cannot_delete_source_job'));
        }

        $embeddingId = $pipelineJob->embedding_id;

        // Explicitly delete KUs produced by this job BEFORE deleting the job
        // itself. The FK is ON DELETE SET NULL by migration, so without this
        // the KUs would become "orphans" (pipeline_job_id=NULL) and linger
        // invisibly — they would still block dataset deletion even though the
        // user has no UI surface to manage them. Deleting them together with
        // the job matches user intent ("delete this clustering run and what
        // it produced").
        DB::transaction(function () use ($pipelineJob) {
            KnowledgeUnit::where('pipeline_job_id', $pipelineJob->id)->delete();
            $pipelineJob->delete();
        });

        // Redirect back to the comparison view for the parent embedding
        if ($embeddingId) {
            return redirect()
                ->route('workspace.embedding', ['embeddingId' => $embeddingId, 'compare' => 1])
                ->with('success', __('ui.clustering_deleted'));
        }

        return redirect()->route('workspace.index')
            ->with('success', __('ui.clustering_deleted'));
    }

    /**
     * Dispatch a ping job to SQS for end-to-end testing.
     */
    public function dispatch(Request $request): RedirectResponse
    {
        $request->validate([
            'dataset_id' => 'required|exists:datasets,id',
        ]);

        $workspaceId = auth()->user()->workspace_id;

        // Create the job record
        $job = PipelineJob::create([
            'workspace_id' => $workspaceId,
            'dataset_id' => $request->input('dataset_id'),
            'status' => 'submitted',
            'progress' => 0,
            'pipeline_config_snapshot_json' => [
                'phase' => '0',
                'step' => 'ping',
                'note' => 'Dashboard dispatch test',
            ],
        ]);

        // Send to SQS via the shared dispatcher.
        try {
            \App\Services\PipelineJobService::dispatch($job, 'ping');

            return redirect()->route('dashboard')
                ->with('success', "Job #{$job->id} dispatched to SQS.");
        } catch (\Exception $e) {
            Log::error('Dashboard: SQS dispatch failed', [
                'job_id' => $job->id,
                'error' => $e->getMessage(),
            ]);

            return redirect()->route('dashboard')
                ->with('error', "Job #{$job->id} created but SQS dispatch failed: {$e->getMessage()}");
        }
    }

    /**
     * Display Knowledge Units for a completed pipeline job.
     */
    public function knowledgeUnits(PipelineJob $pipelineJob): View
    {
        $pipelineJob->load('dataset');

        $knowledgeUnits = KnowledgeUnit::where('pipeline_job_id', $pipelineJob->id)
            ->orderBy('id')
            ->get();

        return view('dashboard.knowledge_units', [
            'job' => $pipelineJob,
            'knowledgeUnits' => $knowledgeUnits,
        ]);
    }

    /**
     * Export Knowledge Units as JSON or CSV.
     */
    public function exportKnowledgeUnits(Request $request, PipelineJob $pipelineJob): JsonResponse|Response
    {
        $format = $request->query('format', 'json');
        $status = $request->query('status', 'approved');

        // CTO directive: default export is approved-only
        $query = KnowledgeUnit::where('pipeline_job_id', $pipelineJob->id);
        if ($status !== 'all') {
            $query->where('review_status', $status);
        }
        $knowledgeUnits = $query->orderBy('id')->get();

        // Build the export payload
        $exportData = $knowledgeUnits->map(fn ($ku) => [
            'id' => $ku->id,
            'topic' => $ku->topic,
            'intent' => $ku->intent,
            'summary' => $ku->summary,
            'keywords' => $ku->keywords_json ?? [],
            'row_count' => $ku->row_count,
            'review_status' => $ku->review_status,
            'version' => $ku->version,
            'confidence' => (float) $ku->confidence,
            'typical_cases' => $ku->typical_cases_json ?? [],
            'cause_summary' => $ku->cause_summary,
            'resolution_summary' => $ku->resolution_summary,
            'created_at' => $ku->created_at->toIso8601String(),
        ]);

        // Render as CSV download if requested; otherwise return JSON
        if ($format === 'csv') {
            $lines = [];
            // CSV header
            $lines[] = implode(',', [
                'id', 'topic', 'intent', 'summary', 'keywords',
                'row_count', 'review_status', 'version', 'confidence', 'created_at',
            ]);
            // CSV rows
            foreach ($exportData as $row) {
                $lines[] = implode(',', [
                    $row['id'],
                    '"' . str_replace('"', '""', $row['topic']) . '"',
                    '"' . str_replace('"', '""', $row['intent']) . '"',
                    '"' . str_replace('"', '""', $row['summary']) . '"',
                    '"' . implode('; ', $row['keywords']) . '"',
                    $row['row_count'],
                    $row['review_status'],
                    $row['version'],
                    $row['confidence'],
                    $row['created_at'],
                ]);
            }

            $filename = "knowledge_units_job_{$pipelineJob->id}.csv";

            return response(implode("\n", $lines))
                ->header('Content-Type', 'text/csv')
                ->header('Content-Disposition', "attachment; filename=\"{$filename}\"");
        }

        // Default: JSON
        return response()->json([
            'job_id' => $pipelineJob->id,
            'dataset' => $pipelineJob->dataset->name ?? null,
            'exported_at' => now()->toIso8601String(),
            'count' => $knowledgeUnits->count(),
            'knowledge_units' => $exportData,
        ]);
    }
}
