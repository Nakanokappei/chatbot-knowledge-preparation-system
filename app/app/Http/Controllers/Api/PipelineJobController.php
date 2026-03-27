<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Dataset;
use App\Models\PipelineJob;
use Aws\Sqs\SqsClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Handle pipeline job creation and status queries.
 *
 * Jobs represent a single pipeline execution. Creating a job triggers
 * the pipeline by dispatching a message to SQS (Phase 0: ping step only).
 */
class PipelineJobController extends Controller
{
    /**
     * List all pipeline jobs for the current workspace.
     */
    public function index(): JsonResponse
    {
        $jobs = PipelineJob::with('dataset:id,name')
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json($jobs);
    }

    /**
     * Show a single pipeline job with its current status and outputs.
     */
    public function show(PipelineJob $pipelineJob): JsonResponse
    {
        $pipelineJob->load('dataset:id,name');
        return response()->json($pipelineJob);
    }

    /**
     * Create a new pipeline job and dispatch to SQS.
     *
     * Phase 0: Creates the job record and sends a ping message to SQS.
     * The Python Worker will receive the message and update the job status.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'dataset_id' => 'required|exists:datasets,id',
        ]);

        $dataset = Dataset::findOrFail($request->input('dataset_id'));

        // Create the pipeline job record
        $job = PipelineJob::create([
            'workspace_id' => auth()->user()->workspace_id,
            'dataset_id' => $dataset->id,
            'status' => 'submitted',
            'progress' => 0,
            'pipeline_config_snapshot_json' => [
                'phase' => '0',
                'step' => 'ping',
                'note' => 'Phase 0 integration test',
            ],
        ]);

        // Dispatch to SQS
        $this->dispatchToSqs($job);

        return response()->json([
            'message' => 'Pipeline job created and dispatched.',
            'pipeline_job' => $job,
        ], 201);
    }

    /**
     * Send a job message to SQS for the Python Worker to consume.
     *
     * Message format follows ADR-0005: SQS + DB Polling.
     */
    private function dispatchToSqs(PipelineJob $job): void
    {
        $message = [
            'job_id' => $job->id,
            'workspace_id' => $job->workspace_id,
            'dataset_id' => $job->dataset_id,
            'step' => 'ping', // Phase 0: ping step only
            'input_s3_path' => null,
            'pipeline_config' => $job->pipeline_config_snapshot_json,
        ];

        try {
            $sqsClient = new SqsClient([
                'region' => config('queue.connections.sqs.region', 'ap-northeast-1'),
                'version' => 'latest',
            ]);

            $queueUrl = config('queue.connections.sqs.prefix') . '/' . config('queue.connections.sqs.queue');

            $sqsClient->sendMessage([
                'QueueUrl' => $queueUrl,
                'MessageBody' => json_encode($message),
            ]);

            Log::info('SQS message dispatched', ['job_id' => $job->id, 'queue' => $queueUrl]);
        } catch (\Exception $e) {
            // Log the error but do not fail the job creation
            Log::error('Failed to dispatch SQS message', [
                'job_id' => $job->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
