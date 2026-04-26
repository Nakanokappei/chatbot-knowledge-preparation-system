"""
Parameter search step — fast sampled clustering sweep across multiple methods.

Samples a subset of embedding vectors (default 1500 rows) and runs clustering
with multiple methods and parameter ranges. Returns silhouette scores for each
combination without creating any DB records (clusters, KUs, etc.).

This allows users to quickly identify optimal clustering parameters before
committing to a full pipeline run.

Sweep configurations:
  - Leiden: resolution in [0.3, 0.5, 0.7, 0.9, 1.0, 1.1, 1.3, 1.5, 2.0]
  - HDBSCAN: min_cluster_size in [5, 10, 15, 20, 30, 50, 80, 100]
  - K-Means: n_clusters in [5, 10, 15, 20, 30, 50, 80]

The wizard-initiated path and the workspace-initiated path both end here:
once the sweep completes, results are stored on the job and the user picks
which patterns to actually run via the existing "Use these params" affordance
in the workspace compare view. No follow-up clustering jobs are auto-created.
"""

import logging

import numpy as np

from src.db import update_job_status, update_job_step_outputs, global_progress
from src.steps.clustering import (
    run_clustering,
    remove_language_direction,
    download_npy_from_s3,
    download_json_from_s3,
)
from src.target_scoring import (
    DEFAULT_TARGET,
    TARGET_PROFILES,
    max_cluster_share_from_labels,
    score_trial,
)
from src.parameter_search_advisor import (
    build_advisor_input,
    generate_advisory_markdown,
)

logger = logging.getLogger(__name__)

# Maximum number of rows to sample for the parameter sweep
SAMPLE_SIZE = 1500

# Drop candidates whose noise ratio exceeds this threshold from the
# "Accepted" group. A high score on "6 tiny clusters carved out of 1,500
# points with 97% noise" is numerically valid but operationally useless.
# Mirrors voice-classifier's MAX_NOISE_RATIO_FOR_SELECTION.
MAX_NOISE_RATIO_FOR_SELECTION: float = 0.5


def build_sweep_configs(n_samples: int) -> list[dict]:
    """
    Build the list of method+parameter combinations to sweep.

    The Leiden n_neighbors value is auto-calculated from the sample size
    using the sqrt(N) heuristic clamped to [5, 100].
    """
    auto_neighbors = min(max(int(np.sqrt(n_samples)), 5), 100)

    configs = []

    # Leiden: vary resolution (primary knob for community granularity)
    for res in [0.3, 0.5, 0.7, 0.9, 1.0, 1.1, 1.3, 1.5, 2.0]:
        configs.append({
            "method": "leiden",
            "params": {"resolution": res, "n_neighbors": auto_neighbors},
            "label": f"Leiden res={res}",
        })

    # HDBSCAN: vary min_cluster_size (primary knob for cluster density)
    for mcs in [5, 10, 15, 20, 30, 50, 80, 100]:
        configs.append({
            "method": "hdbscan",
            "params": {"min_cluster_size": mcs, "min_samples": 5},
            "label": f"HDBSCAN min={mcs}",
        })

    # K-Means: vary n_clusters (direct cluster count control)
    for k in [5, 10, 15, 20, 30, 50, 80]:
        configs.append({
            "method": "kmeans",
            "params": {"n_clusters": k},
            "label": f"K-Means k={k}",
        })

    return configs


def execute(job_id: int, tenant_id: int, dataset_id: int = None,
            input_s3_path: str = None, pipeline_config: dict = None, **kwargs):
    """
    Execute the parameter search step.

    1. Load embedding vectors from S3
    2. Random-sample down to SAMPLE_SIZE rows
    3. Optionally apply language debiasing
    4. Run each sweep configuration and record silhouette + cluster count
    5. Store results in step_outputs_json (no DB side effects)
    6. Mark job as completed (no chaining to next step)
    """
    if pipeline_config is None:
        pipeline_config = {}

    logger.info("Parameter search started for job %d", job_id)
    update_job_status(job_id, status="parameter_search", progress=5)

    # Step 1: Load full embedding vectors from S3
    embeddings_full = download_npy_from_s3(input_s3_path)
    total_rows = len(embeddings_full)
    logger.info("Loaded embeddings: shape=%s", embeddings_full.shape)

    # Step 2: Random sample to keep the sweep fast
    if total_rows > SAMPLE_SIZE:
        rng = np.random.default_rng(seed=42)
        indices = rng.choice(total_rows, SAMPLE_SIZE, replace=False)
        sample = embeddings_full[indices]
        logger.info("Sampled %d / %d rows for parameter sweep", SAMPLE_SIZE, total_rows)
    else:
        sample = embeddings_full
        indices = np.arange(total_rows)
        logger.info("Using all %d rows (below sample threshold)", total_rows)

    sample_size = len(sample)
    update_job_status(job_id, status="parameter_search", progress=15)

    # Step 3: Load row_ids for the sampled subset.
    # Always loaded (cheap S3 read) because the advisor needs them to fetch
    # representative raw_text from dataset_rows for the top sample clusters.
    # Language debiasing optionally consumes the same array.
    row_ids_path = input_s3_path.replace("embeddings.npy", "row_ids.json")
    sampled_row_ids: list | None = None
    try:
        all_row_ids = download_json_from_s3(row_ids_path)
        sampled_row_ids = [all_row_ids[i] for i in indices]
    except Exception as e:
        logger.warning(
            "Could not load row_ids.json (%s) — advisor representatives "
            "will be unavailable", e,
        )

    remove_lang_bias = pipeline_config.get("remove_language_bias", True)
    if remove_lang_bias and sampled_row_ids is not None:
        try:
            sample, _ = remove_language_direction(sample, sampled_row_ids)
            logger.info("Applied language debiasing to sample")
        except Exception as e:
            logger.warning("Language debiasing skipped: %s", e)

    update_job_status(job_id, status="parameter_search", progress=20)

    # Step 4: Run the sweep — each configuration gets clustering + silhouette
    configs = build_sweep_configs(sample_size)
    results = []

    # Optional ranking target — if not provided, default to "chatbot" since
    # KPS's downstream use case is FAQ / chatbot intent design. The PHP report
    # shows scores under all three targets so the user can compare.
    target = (pipeline_config.get("target") or DEFAULT_TARGET).lower()
    if target not in TARGET_PROFILES:
        logger.warning("Unknown target '%s'; falling back to %s", target, DEFAULT_TARGET)
        target = DEFAULT_TARGET

    # Cache winner sample labels so the advisor digest can derive top-N
    # cluster sizes / representative rows from the same sample we scored.
    winner_sample_labels: np.ndarray | None = None
    winner_score: float = -1.0

    for i, config in enumerate(configs):
        try:
            labels, _, method_used, effective_params = run_clustering(
                sample, method=config["method"], params=config["params"],
            )

            # Count clusters (excluding noise label -1)
            unique_labels = set(labels)
            n_clusters = len(unique_labels) - (1 if -1 in unique_labels else 0)
            n_noise = int((labels == -1).sum())

            # Compute silhouette score (requires at least 2 clusters).
            # Use cosine metric for text embeddings — the project displays the
            # score on a [-1, 1] scale calibrated for cosine. sklearn defaults
            # to Euclidean which produces incomparable values. The sample is
            # already capped at SAMPLE_SIZE (1500) above, so the O(n²) cosine
            # pairwise matrix stays bounded and we don't need extra sampling
            # inside silhouette_score itself.
            sil = -1.0
            non_noise = labels != -1
            if n_clusters >= 2 and non_noise.sum() > n_clusters:
                from sklearn.metrics import silhouette_score
                sil = float(silhouette_score(
                    sample[non_noise], labels[non_noise], metric='cosine',
                ))

            # Largest-cluster share — drives the share_penalty in the
            # target scorer and shows up directly in the report.
            max_share = max_cluster_share_from_labels(labels, sample_size)

            trial = {
                "method": config["method"],
                "label": config["label"],
                "params": effective_params,
                "n_clusters": n_clusters,
                "silhouette_score": round(sil, 4),
                "n_noise": n_noise,
                "max_cluster_share": round(max_share, 4),
                # Pre-computed scores under all three targets so the PHP
                # report renders the table without re-implementing the
                # scoring formula in Blade. Keeping these here also means
                # the worker is the single source of truth for ranking.
                "score_faq": round(score_trial(sil, n_clusters, max_share, "faq"), 4),
                "score_chatbot": round(score_trial(sil, n_clusters, max_share, "chatbot"), 4),
                "score_insight": round(score_trial(sil, n_clusters, max_share, "insight"), 4),
            }
            results.append(trial)

            # Track the active-target winner during the sweep so we can
            # later pull representatives from the matching label array.
            if (n_noise / sample_size) <= MAX_NOISE_RATIO_FOR_SELECTION:
                this_score = trial[f"score_{target}"]
                if this_score > winner_score:
                    winner_score = this_score
                    winner_sample_labels = labels.copy()
        except Exception as e:
            logger.warning("Sweep config %s failed: %s", config["label"], e)
            results.append({
                "method": config["method"],
                "label": config["label"],
                "params": config["params"],
                "n_clusters": 0,
                "silhouette_score": -1.0,
                "n_noise": 0,
                "max_cluster_share": 0.0,
                "score_faq": 0.0,
                "score_chatbot": 0.0,
                "score_insight": 0.0,
                "error": str(e),
            })

        # Update progress proportionally through the sweep
        progress = 20 + int((i + 1) / len(configs) * 75)
        update_job_status(job_id, status="parameter_search", progress=progress)

    # Step 5: Sort by silhouette descending so the chart UI keeps its existing
    # bar order (highest silhouette first). The PHP report re-sorts by the
    # active target's score for the "Accepted Candidates" table.
    results.sort(key=lambda r: -r["silhouette_score"])

    # Step 6: Generate the LLM advisory using the winner's sample labels.
    # Bedrock Claude is invoked once for the whole digest. If anything fails
    # (no winner, naming/advisor errors), the report still renders without
    # an advisory section.
    advisory_md: str = ""
    top_clusters_meta: list[dict] = []
    advisor_meta: dict = {}
    # The advisor must use the workspace-approved Bedrock model only —
    # never a worker-side fallback. The full-pipeline job that produced
    # this embedding has the workspace's selected model_id stamped in
    # its pipeline_config_snapshot, and parameterSearch inherits that
    # config when dispatching, so the value flows through to here. If
    # it is missing for any reason we skip the advisor rather than
    # silently invoking an un-approved model.
    advisor_model_id = pipeline_config.get("llm_model_id")
    try:
        if winner_sample_labels is not None and advisor_model_id:
            update_job_status(job_id, status="parameter_search", progress=96)
            digest_input = build_advisor_input(
                results=results,
                sample_size=sample_size,
                total_rows=total_rows,
                target=target,
                winner_sample_labels=winner_sample_labels,
                sampled_row_ids=sampled_row_ids,
                input_s3_path=input_s3_path,
            )
            advisory_md, top_clusters_meta, advisor_meta = generate_advisory_markdown(
                digest_input, model_id=advisor_model_id,
            )
        elif winner_sample_labels is None:
            logger.info(
                "No candidate passed the noise-ratio filter; advisory skipped"
            )
        else:
            logger.info(
                "Advisory skipped: pipeline_config['llm_model_id'] not set. "
                "Advisor runs only against workspace-approved models."
            )
    except Exception as e:
        # Soft-fail: the report is still useful without the advisory.
        logger.warning("Advisory generation failed: %s", e, exc_info=True)

    update_job_step_outputs(job_id, "parameter_search", {
        "sample_size": sample_size,
        "total_rows": total_rows,
        "configs_tested": len(configs),
        "target": target,
        "noise_ratio_threshold": MAX_NOISE_RATIO_FOR_SELECTION,
        "results": results,
        "advisory_markdown": advisory_md,
        "top_clusters": top_clusters_meta,
        "advisor_meta": advisor_meta,
    })

    # Step 7: Mark complete — no chaining (this is a standalone analysis step)
    update_job_status(job_id, status="completed", progress=100)

    logger.info(
        "Parameter search completed for job %d: %d configs tested, best silhouette=%.4f (%s), target=%s, advisory=%s",
        job_id, len(configs),
        results[0]["silhouette_score"] if results else -1,
        results[0]["label"] if results else "none",
        target,
        "yes" if advisory_md else "no",
    )
