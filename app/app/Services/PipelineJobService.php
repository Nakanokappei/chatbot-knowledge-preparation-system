<?php

namespace App\Services;

use App\Models\PipelineJob;

/**
 * High-level operations on PipelineJob rows.
 *
 * Sits one layer above SqsDispatcher: SqsDispatcher knows how to talk
 * to SQS in the abstract; this service knows how that talk relates to
 * a PipelineJob row in the database. It exists so controllers don't
 * have to keep re-extracting (job_id, workspace_id, dataset_id,
 * pipeline_config) tuples from the same job object every time they
 * want to enqueue work.
 *
 * Why a separate class from SqsDispatcher? SqsDispatcher is intentionally
 * model-agnostic — it could be reused for any future SQS consumer that
 * doesn't have a PipelineJob row (e.g. an admin-triggered cleanup queue).
 * Coupling job-shape knowledge here keeps that boundary clean.
 */
class PipelineJobService
{
    /**
     * Send a PipelineJob row to the worker queue.
     *
     * Pulls workspace_id / dataset_id / pipeline_config out of the job
     * automatically, so the caller only has to specify which step the
     * worker should run. `configOverride` lets a caller substitute a
     * different config than the job's snapshot (used by recluster /
     * parameterSearch which inject embedding_id at dispatch time
     * without mutating the snapshot).
     *
     * Returns true when the message was queued, false when SQS isn't
     * configured (the caller decides whether that's fatal).
     */
    public static function dispatch(
        PipelineJob $job,
        string $step,
        ?array $configOverride = null,
        ?string $inputS3Path = null,
    ): bool {
        return SqsDispatcher::dispatch(
            jobId: $job->id,
            workspaceId: $job->workspace_id,
            datasetId: $job->dataset_id,
            step: $step,
            pipelineConfig: $configOverride ?? $job->pipeline_config_snapshot_json ?? [],
            inputS3Path: $inputS3Path,
        );
    }

    /**
     * Atomic create+dispatch for the common pattern of "make a new
     * PipelineJob and immediately hand the first step to the worker".
     *
     * Use this when a controller creates a single job and dispatches
     * it in one breath. For multi-job creation flows (e.g. dataset
     * wizard creating N clustering-only jobs but only dispatching the
     * first), call PipelineJob::create() then PipelineJobService::dispatch()
     * separately so the caller can choose which job to dispatch.
     */
    public static function createAndDispatch(
        array $jobAttrs,
        string $step,
        ?array $configOverride = null,
        ?string $inputS3Path = null,
    ): PipelineJob {
        $job = PipelineJob::create($jobAttrs);
        self::dispatch($job, $step, $configOverride, $inputS3Path);
        return $job;
    }
}
