<?php

namespace App\Support;

/**
 * Target-aware scoring for parameter-search trials.
 *
 * Mirror of `worker/src/target_scoring.py` so the PHP report can re-rank
 * trials under any of the three downstream targets without round-tripping
 * to the worker. The worker pre-computes per-target scores when the sweep
 * runs, but porting the formula here keeps PHP self-sufficient for legacy
 * jobs that pre-date the worker change and lack the score columns.
 */
class ParameterSearchScoring
{
    public const DEFAULT_TARGET = 'chatbot';

    /**
     * Per-target tuning profile.
     *
     *   - "faq"     prefers 30-80 clusters, no single cluster > 10% share.
     *   - "chatbot" prefers 50-150 clusters, no single cluster > 7% share.
     *   - "insight" maximises raw silhouette (no granularity / share bias).
     */
    public const PROFILES = [
        'faq' => [
            'cluster_range' => [30, 80],
            'max_cluster_share' => 0.10,
        ],
        'chatbot' => [
            'cluster_range' => [50, 150],
            'max_cluster_share' => 0.07,
        ],
        'insight' => [
            'cluster_range' => null,
            'max_cluster_share' => 1.0,
        ],
    ];

    /**
     * Compute a positive-only ranking score for one trial under the given target.
     *
     * Returns 0.0 when the trial is degenerate (no silhouette, or fewer than
     * two clusters) so unscoreable trials never out-rank usable ones.
     */
    public static function score(
        ?float $silhouette,
        int $nClusters,
        float $maxClusterShare,
        string $target = self::DEFAULT_TARGET,
    ): float {
        // Reject degenerate trials before any multiplication so the floor
        // value cannot rescue them.
        if ($silhouette === null || $silhouette <= -1.0 || $nClusters < 2) {
            return 0.0;
        }

        // Floor of 0.05 keeps weak-but-valid silhouettes comparable when
        // granularity / share are the deciding signals.
        $base = max((float) $silhouette, 0.05);
        $granularity = self::granularityFit($nClusters, $target);
        $share = self::sharePenalty($maxClusterShare, $target);
        return $base * $granularity * $share;
    }

    /**
     * Granularity multiplier in [0.1, 1.0].
     *
     * 1.0 inside the target cluster-count range, with a steep penalty below
     * the range (under-clustering is the real failure mode for FAQ /
     * chatbot use cases) and a gentler penalty above (over-clustered output
     * is recoverable by manual merging).
     */
    public static function granularityFit(int $nClusters, string $target): float
    {
        $profile = self::PROFILES[$target] ?? self::PROFILES[self::DEFAULT_TARGET];
        $range = $profile['cluster_range'];
        if ($range === null) {
            return 1.0;
        }

        [$low, $high] = $range;
        if ($nClusters >= $low && $nClusters <= $high) {
            return 1.0;
        }
        if ($nClusters < $low) {
            return max(0.1, $nClusters / $low);
        }
        return max(0.3, 1.0 - ($nClusters - $high) / ($high * 2.5));
    }

    /**
     * Share-penalty multiplier; tapers off when one cluster owns too much
     * of the data, floored at 0.2 so a single-dominant-but-otherwise-good
     * candidate can still win when nothing better exists.
     */
    public static function sharePenalty(float $maxShare, string $target): float
    {
        $profile = self::PROFILES[$target] ?? self::PROFILES[self::DEFAULT_TARGET];
        $budget = (float) $profile['max_cluster_share'];
        if ($maxShare <= $budget) {
            return 1.0;
        }
        return max(0.2, 1.0 - ($maxShare - $budget) * 3.0);
    }

    /**
     * Localised display label for a target id ("faq" / "chatbot" / "insight").
     *
     * Falls back to the raw id when an unknown target is passed.
     */
    public static function targetLabel(string $target): string
    {
        return match ($target) {
            'faq' => 'FAQ ページ作成',
            'chatbot' => 'チャットボット質問タイプ設計',
            'insight' => '探索的分析',
            default => $target,
        };
    }
}
