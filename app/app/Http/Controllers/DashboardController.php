<?php

namespace App\Http\Controllers;

use App\Models\Cluster;
use App\Models\Dataset;
use App\Models\DatasetRow;
use App\Models\KnowledgeUnit;
use App\Models\LlmModel;
use App\Models\PipelineJob;
use Aws\Sqs\SqsClient;
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
        // Use a fixed tenant for Phase 0 (no login UI yet)
        $tenantId = auth()->user()->tenant_id;

        $jobs = PipelineJob::with('dataset:id,name')
            ->where('tenant_id', $tenantId)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        $datasets = Dataset::where('tenant_id', $tenantId)->get();

        // Load active LLM models for the pipeline dispatch dropdown
        $llmModels = LlmModel::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        $stats = [
            'total' => $jobs->count(),
            'completed' => $jobs->where('status', 'completed')->count(),
            'processing' => $jobs->whereNotIn('status', ['completed', 'failed', 'submitted'])->count(),
            'failed' => $jobs->where('status', 'failed')->count(),
        ];

        return view('dashboard.index', compact('jobs', 'datasets', 'stats', 'llmModels'));
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
     * Dispatch a pipeline job (preprocess step) to SQS.
     */
    public function dispatchPipeline(Request $request): RedirectResponse
    {
        $request->validate([
            'dataset_id' => 'required|exists:datasets,id',
            'llm_model_id' => 'nullable|string|max:100',
        ]);

        $tenantId = auth()->user()->tenant_id;

        // Resolve the default LLM model from the database registry
        $defaultModel = LlmModel::where('tenant_id', $tenantId)
            ->where('is_default', true)
            ->first();
        $defaultLlmModel = $defaultModel?->model_id ?? 'jp.anthropic.claude-haiku-4-5-20251001-v1:0';

        // Create the job record for a full pipeline run
        $job = PipelineJob::create([
            'tenant_id' => $tenantId,
            'dataset_id' => $request->input('dataset_id'),
            'status' => 'submitted',
            'progress' => 0,
            'pipeline_config_snapshot_json' => [
                'phase' => '1',
                'llm_model_id' => $request->input('llm_model_id', $defaultLlmModel),
                'embedding_model' => 'amazon.titan-embed-text-v2:0',
                'embedding_dimension' => 1024,
                'clustering_method' => 'hdbscan',
                'min_cluster_size' => 15,
            ],
        ]);

        // Send first step (preprocess) to SQS
        try {
            $sqs = new SqsClient([
                'region' => config('queue.connections.sqs.region', 'ap-northeast-1'),
                'version' => 'latest',
            ]);

            $queueUrl = config('queue.connections.sqs.prefix') . '/' . config('queue.connections.sqs.queue');

            $sqs->sendMessage([
                'QueueUrl' => $queueUrl,
                'MessageBody' => json_encode([
                    'job_id' => $job->id,
                    'tenant_id' => $job->tenant_id,
                    'dataset_id' => $job->dataset_id,
                    'step' => 'preprocess',
                    'input_s3_path' => null,
                    'pipeline_config' => $job->pipeline_config_snapshot_json,
                ]),
            ]);

            return redirect()->route('dashboard')
                ->with('success', "Pipeline Job #{$job->id} dispatched (preprocess -> embedding -> clustering).");
        } catch (\Exception $e) {
            return redirect()->route('dashboard')
                ->with('error', "Job #{$job->id} created but dispatch failed: {$e->getMessage()}");
        }
    }

    /**
     * Dispatch a ping job to SQS for end-to-end testing.
     */
    public function dispatch(Request $request): RedirectResponse
    {
        $request->validate([
            'dataset_id' => 'required|exists:datasets,id',
        ]);

        $tenantId = auth()->user()->tenant_id;

        // Create the job record
        $job = PipelineJob::create([
            'tenant_id' => $tenantId,
            'dataset_id' => $request->input('dataset_id'),
            'status' => 'submitted',
            'progress' => 0,
            'pipeline_config_snapshot_json' => [
                'phase' => '0',
                'step' => 'ping',
                'note' => 'Dashboard dispatch test',
            ],
        ]);

        // Send to SQS
        try {
            $sqs = new SqsClient([
                'region' => config('queue.connections.sqs.region', 'ap-northeast-1'),
                'version' => 'latest',
            ]);

            $queueUrl = config('queue.connections.sqs.prefix') . '/' . config('queue.connections.sqs.queue');

            $sqs->sendMessage([
                'QueueUrl' => $queueUrl,
                'MessageBody' => json_encode([
                    'job_id' => $job->id,
                    'tenant_id' => $job->tenant_id,
                    'dataset_id' => $job->dataset_id,
                    'step' => 'ping',
                    'input_s3_path' => null,
                    'pipeline_config' => $job->pipeline_config_snapshot_json,
                ]),
            ]);

            Log::info('Dashboard: SQS message dispatched', ['job_id' => $job->id]);

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
