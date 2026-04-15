<?php

namespace App\Services;

use Aws\Sqs\SqsClient;
use Illuminate\Support\Facades\Log;

/**
 * Single dispatch surface for queueing pipeline-job messages onto SQS.
 *
 * Three controllers (DatasetWizardController::finalize, EmbeddingController::
 * parameterSearch, DashboardController::retryJob, DashboardController::
 * dispatch) each used to:
 *   1. Resolve the queue URL by checking SQS_QUEUE_URL env, falling back
 *      to SQS_PREFIX + '/' + SQS_QUEUE.
 *   2. Construct an SqsClient with region from SQS_REGION env.
 *   3. Build a message body with the same { job_id, workspace_id,
 *      dataset_id, step, input_s3_path, pipeline_config } shape that the
 *      Python worker expects.
 *   4. Call sendMessage and log the outcome.
 *
 * That four-step ritual was repeated verbatim in three places, with subtle
 * drift over time (one site logs success, another doesn't; one site uses
 * env() inline, another caches the URL). This service is the canonical
 * implementation.
 *
 * Worker contract: keep the body keys aligned with worker/src/main.py's
 * STEP_HANDLERS dispatch — `step` selects the handler, the rest is passed
 * through as kwargs.
 */
class SqsDispatcher
{
    /**
     * Send a pipeline-step message to the configured SQS queue.
     *
     * Returns true if the message was dispatched, false if SQS isn't
     * configured (rare — only happens before terraform apply or in some
     * unit-test environments). Callers can decide whether that's fatal:
     * the wizard treats it as "job created but stuck", whereas the retry
     * path treats it as silent success.
     */
    public static function dispatch(
        int $jobId,
        int $workspaceId,
        ?int $datasetId,
        string $step,
        ?array $pipelineConfig = null,
        ?string $inputS3Path = null,
    ): bool {
        $queueUrl = self::resolveQueueUrl();
        if (!$queueUrl) {
            Log::warning("SQS not configured — job {$jobId} not dispatched (step={$step})");
            return false;
        }

        $body = [
            'job_id' => $jobId,
            'workspace_id' => $workspaceId,
            'dataset_id' => $datasetId,
            'step' => $step,
            'input_s3_path' => $inputS3Path,
            'pipeline_config' => $pipelineConfig ?? [],
        ];

        $sqs = new SqsClient([
            'region' => env('SQS_REGION', 'ap-northeast-1'),
            'version' => 'latest',
        ]);
        $sqs->sendMessage([
            'QueueUrl' => $queueUrl,
            'MessageBody' => json_encode($body),
        ]);

        Log::info("Pipeline job {$jobId} dispatched to SQS (step={$step})");
        return true;
    }

    /**
     * Resolve the queue URL from environment, supporting both the direct
     * SQS_QUEUE_URL form and the legacy SQS_PREFIX + SQS_QUEUE form
     * carried over from earlier deployments.
     */
    private static function resolveQueueUrl(): ?string
    {
        $direct = env('SQS_QUEUE_URL');
        if ($direct) {
            return $direct;
        }
        $prefix = env('SQS_PREFIX', '');
        $queue = env('SQS_QUEUE', 'ckps-pipeline-dev');
        if ($prefix) {
            return $prefix . '/' . $queue;
        }
        return null;
    }
}
