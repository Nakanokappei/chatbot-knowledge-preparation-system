<?php

namespace App\Enums;

/**
 * Enumerated states of a pipeline_jobs.status row.
 *
 * Matches the constants on PipelineJob::STATUSES and the worker's
 * STEP_HANDLERS keys (worker/src/main.py). Keeping this enum as the
 * single source of truth for step → display-label mapping means any
 * future status (e.g. an export step) only needs to be added in two
 * places: this enum and the corresponding ui.* translation key.
 *
 * Cases use PHP-conventional PascalCase; the backing string values are
 * the snake_case identifiers that the database column and SQS messages
 * carry. Use `from()` / `tryFrom()` to convert.
 */
enum PipelineStep: string
{
    // Pre-execution states
    case Submitted = 'submitted';
    case Queued = 'queued';

    // Worker-driven step states (in execution order)
    case Preprocess = 'preprocess';
    case Embedding = 'embedding';
    case Clustering = 'clustering';
    case ClusterAnalysis = 'cluster_analysis';
    case KnowledgeUnitGeneration = 'knowledge_unit_generation';

    // Standalone analysis step (does not chain forward)
    case ParameterSearch = 'parameter_search';

    // Terminal states
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /**
     * Human-readable label for the current locale. Falls back to the raw
     * status string if the translation key is missing — the same defensive
     * behaviour the previous inline `$stepLabels[$status] ?? $status`
     * pattern provided.
     */
    public function label(): string
    {
        $key = match ($this) {
            self::Submitted => 'ui.step_submitted',
            self::Queued => 'ui.step_queued',
            self::Preprocess => 'ui.step_preprocess',
            self::Embedding => 'ui.step_embedding',
            self::Clustering => 'ui.step_clustering',
            self::ClusterAnalysis => 'ui.step_cluster_analysis',
            self::KnowledgeUnitGeneration => 'ui.step_ku_generation',
            self::ParameterSearch => 'ui.step_parameter_search',
            self::Completed => 'ui.step_completed',
            self::Failed => 'ui.step_failed',
            self::Cancelled => 'ui.step_cancelled',
        };
        $translation = __($key);
        // __() returns the key itself when the translation is missing.
        return $translation === $key ? $this->value : $translation;
    }

    /**
     * Resolve a label for an arbitrary status string. Used by views that
     * render any pipeline_jobs row without needing to know whether the
     * value is a known case (e.g. legacy rows or future statuses).
     */
    public static function labelFor(?string $status): string
    {
        if ($status === null || $status === '') {
            return '';
        }
        $case = self::tryFrom($status);
        return $case ? $case->label() : $status;
    }

    /**
     * Return the full status → label map. Useful when a view needs all
     * labels at once (e.g. for a status filter dropdown).
     */
    public static function labels(): array
    {
        $out = [];
        foreach (self::cases() as $case) {
            $out[$case->value] = $case->label();
        }
        return $out;
    }
}
