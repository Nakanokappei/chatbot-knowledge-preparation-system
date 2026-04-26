"""
Target-aware scoring for parameter-search candidates.

Ported from voice-classifier `src/tuner.py` so that KPS's parameter sweep
can rank candidates the same way: silhouette is the base signal, but
candidates are penalised when their cluster count is far from the target
range, or when a single cluster swallows too much of the data.

Three downstream targets are supported:

    - "faq"     prefers 30-80 clusters, no single cluster > 10% share.
    - "chatbot" prefers 50-150 clusters, no single cluster > 7% share.
    - "insight" maximises raw silhouette (no granularity / share bias).

The resulting score is positive-only; higher is better. KPS displays the
score for all three targets in the parameter-search report so the operator
can see how the choice would differ for another use case.
"""

from __future__ import annotations

from typing import Literal

Target = Literal["faq", "chatbot", "insight"]
DEFAULT_TARGET: Target = "chatbot"

# Per-target configuration: the sweet-spot cluster-count range, and a ceiling
# on how much of the data any single cluster is allowed to absorb. A single
# cluster with > the budget tends to collapse distinct topics into one entry.
TARGET_PROFILES: dict[Target, dict] = {
    "faq": {
        "cluster_range": (30, 80),
        "max_cluster_share": 0.10,
    },
    "chatbot": {
        "cluster_range": (50, 150),
        "max_cluster_share": 0.07,
    },
    "insight": {
        "cluster_range": None,
        "max_cluster_share": 1.0,
    },
}


def score_trial(
    silhouette: float | None,
    n_clusters: int,
    max_cluster_share: float,
    target: Target = DEFAULT_TARGET,
) -> float:
    """Compute the target-aware selection score for one candidate.

    Returns 0.0 when the trial is not scoreable (no silhouette or fewer
    than two clusters), so unscoreable trials never win the selection.

    Formula:

        score = max(silhouette, 0.05)
              * granularity_fit(n_clusters, target)
              * share_penalty(max_cluster_share, target)
    """
    # Reject degenerate trials early — they should not influence the ranking.
    if silhouette is None or silhouette <= -1.0 or n_clusters < 2:
        return 0.0

    # Tiny floor keeps candidates comparable when the embedding produces
    # very small silhouettes — at that point, granularity and share
    # factors should be the deciding signal.
    base = max(float(silhouette), 0.05)
    granularity = _granularity_fit(n_clusters, target)
    share = _share_penalty(max_cluster_share, target)
    return base * granularity * share


def _granularity_fit(n_clusters: int, target: Target) -> float:
    """How well does this cluster count match the target range?

    Returns a multiplier in [0.1, 1.0]:
        - 1.0 when n_clusters is inside the target range.
        - Steep penalty below the range (under-clustering is the real failure
          mode for FAQ / chatbot — two huge buckets are unusable).
        - Gentler penalty above the range (over-clustering is recoverable
          by merging similar groups later).
    """
    profile = TARGET_PROFILES[target]
    cluster_range = profile["cluster_range"]
    if cluster_range is None:
        return 1.0  # insight: no bias

    low, high = cluster_range
    if low <= n_clusters <= high:
        return 1.0
    if n_clusters < low:
        return max(0.1, n_clusters / low)
    return max(0.3, 1.0 - (n_clusters - high) / (high * 2.5))


def _share_penalty(max_share: float, target: Target) -> float:
    """Down-weight candidates where a single cluster dominates the data."""
    profile = TARGET_PROFILES[target]
    budget = float(profile["max_cluster_share"])
    if max_share <= budget:
        return 1.0
    # Taper off as we exceed the budget; floor at 0.2 so a single-dominant
    # but otherwise-reasonable candidate can still win when nothing better
    # exists.
    return max(0.2, 1.0 - (max_share - budget) * 3.0)


def max_cluster_share_from_labels(labels, sample_size: int) -> float:
    """Share of the largest non-noise cluster in the label array.

    Accepts numpy ndarray or any iterable of integer labels (-1 = noise).
    Returns 0.0 when the sample is empty or every label is noise.
    """
    if sample_size <= 0:
        return 0.0
    from collections import Counter

    counts = Counter(int(label) for label in labels if int(label) != -1)
    if not counts:
        return 0.0
    return max(counts.values()) / sample_size
