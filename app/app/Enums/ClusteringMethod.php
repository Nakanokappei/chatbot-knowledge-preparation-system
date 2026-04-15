<?php

namespace App\Enums;

/**
 * Clustering algorithms supported by the Python worker.
 *
 * Single source of truth for:
 *   - the canonical method identifier carried in pipeline_jobs
 *     (clustering_method column / pipeline_config snapshot)
 *   - the short display name shown in the comparison table
 *   - the verbose descriptor shown in the embedding detail view
 *   - the badge colour used by the parameter-search chart and
 *     compare-view indicators in JavaScript
 *
 * Worker registry: worker/src/steps/clustering.py CLUSTERING_METHODS.
 * Adding a method here without adding it there (and vice-versa) is a bug.
 */
enum ClusteringMethod: string
{
    case HDBSCAN = 'hdbscan';
    case KMeans = 'kmeans';
    case Agglomerative = 'agglomerative';
    case Leiden = 'leiden';

    /** Compact name shown in the comparison table and sidebar. */
    public function displayName(): string
    {
        return match ($this) {
            self::HDBSCAN => 'HDBSCAN',
            self::KMeans => 'K-Means++',
            self::Agglomerative => 'Agglomerative',
            self::Leiden => 'Leiden (Graph)',
        };
    }

    /** Verbose name with algorithm character, used on the detail page. */
    public function verboseName(): string
    {
        return match ($this) {
            self::HDBSCAN => 'HDBSCAN (density-based, auto)',
            self::KMeans => 'K-Means++ (spherical)',
            self::Agglomerative => 'Agglomerative (hierarchical)',
            self::Leiden => 'HNSW + Leiden (graph community)',
        };
    }

    /** Hex colour used by the parameter-search chart bars and legends. */
    public function color(): string
    {
        return match ($this) {
            self::HDBSCAN => '#ff9500',       // amber
            self::KMeans => '#30d158',        // green
            self::Agglomerative => '#af52de', // purple
            self::Leiden => '#0071e3',        // blue (recommended default)
        };
    }

    // ---------------------------------------------------------------------
    // Static helpers used by views to resolve names/colours by raw value.
    // ---------------------------------------------------------------------

    /** Compact name for a raw method string, with safe fallback. */
    public static function displayNameFor(?string $method): string
    {
        if (!$method) {
            return '';
        }
        $case = self::tryFrom($method);
        return $case ? $case->displayName() : strtoupper($method);
    }

    /** Verbose name for a raw method string, with safe fallback. */
    public static function verboseNameFor(?string $method): string
    {
        if (!$method) {
            return '';
        }
        $case = self::tryFrom($method);
        return $case ? $case->verboseName() : strtoupper($method);
    }

    /**
     * Build a JS-friendly map for emission via `@json()` in a blade.
     *
     * Shape: `{ hdbscan: { name: 'HDBSCAN', color: '#ff9500' }, ... }`.
     * The frontend reads this once and derives both METHOD_COLORS and any
     * legend labels from a single source.
     */
    public static function frontendConfig(): array
    {
        $out = [];
        foreach (self::cases() as $case) {
            $out[$case->value] = [
                'name' => $case->displayName(),
                'color' => $case->color(),
            ];
        }
        return $out;
    }
}
