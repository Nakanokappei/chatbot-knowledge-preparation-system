<?php

namespace App\Http\Controllers;

use App\Models\Cluster;
use App\Models\Dataset;
use App\Models\DatasetRow;
use App\Models\PipelineJob;
use Aws\Sqs\SqsClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        $tenantId = 1;

        $jobs = PipelineJob::with('dataset:id,name')
            ->where('tenant_id', $tenantId)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        $datasets = Dataset::where('tenant_id', $tenantId)->get();

        $stats = [
            'total' => $jobs->count(),
            'completed' => $jobs->where('status', 'completed')->count(),
            'processing' => $jobs->whereNotIn('status', ['completed', 'failed', 'submitted'])->count(),
            'failed' => $jobs->where('status', 'failed')->count(),
        ];

        return view('dashboard.index', compact('jobs', 'datasets', 'stats'));
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
        ]);

        $tenantId = 1;

        // Create the job record for a full pipeline run
        $job = PipelineJob::create([
            'tenant_id' => $tenantId,
            'dataset_id' => $request->input('dataset_id'),
            'status' => 'submitted',
            'progress' => 0,
            'pipeline_config_snapshot_json' => [
                'phase' => '1',
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

        $tenantId = 1;

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
}
