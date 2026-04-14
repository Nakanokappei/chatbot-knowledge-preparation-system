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

logger = logging.getLogger(__name__)

# Maximum number of rows to sample for the parameter sweep
SAMPLE_SIZE = 1500


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

    # Step 3: Optional language direction removal
    remove_lang_bias = pipeline_config.get("remove_language_bias", True)
    if remove_lang_bias:
        # Load row_ids for the sampled subset to enable language detection
        row_ids_path = input_s3_path.replace("embeddings.npy", "row_ids.json")
        try:
            all_row_ids = download_json_from_s3(row_ids_path)
            sampled_row_ids = [all_row_ids[i] for i in indices]
            sample, _ = remove_language_direction(sample, sampled_row_ids)
            logger.info("Applied language debiasing to sample")
        except Exception as e:
            logger.warning("Language debiasing skipped: %s", e)

    update_job_status(job_id, status="parameter_search", progress=20)

    # Step 4: Run the sweep — each configuration gets clustering + silhouette
    configs = build_sweep_configs(sample_size)
    results = []

    for i, config in enumerate(configs):
        try:
            labels, _, method_used, effective_params = run_clustering(
                sample, method=config["method"], params=config["params"],
            )

            # Count clusters (excluding noise label -1)
            unique_labels = set(labels)
            n_clusters = len(unique_labels) - (1 if -1 in unique_labels else 0)
            n_noise = int((labels == -1).sum())

            # Compute silhouette score (requires at least 2 clusters)
            sil = -1.0
            non_noise = labels != -1
            if n_clusters >= 2 and non_noise.sum() > n_clusters:
                from sklearn.metrics import silhouette_score
                sil = float(silhouette_score(sample[non_noise], labels[non_noise]))

            results.append({
                "method": config["method"],
                "label": config["label"],
                "params": effective_params,
                "n_clusters": n_clusters,
                "silhouette_score": round(sil, 4),
                "n_noise": n_noise,
            })
        except Exception as e:
            logger.warning("Sweep config %s failed: %s", config["label"], e)
            results.append({
                "method": config["method"],
                "label": config["label"],
                "params": config["params"],
                "n_clusters": 0,
                "silhouette_score": -1.0,
                "n_noise": 0,
                "error": str(e),
            })

        # Update progress proportionally through the sweep
        progress = 20 + int((i + 1) / len(configs) * 75)
        update_job_status(job_id, status="parameter_search", progress=progress)

    # Step 5: Sort by silhouette descending and store results
    results.sort(key=lambda r: -r["silhouette_score"])

    update_job_step_outputs(job_id, "parameter_search", {
        "sample_size": sample_size,
        "total_rows": total_rows,
        "configs_tested": len(configs),
        "results": results,
    })

    # Step 6: Mark complete — no chaining (this is a standalone analysis step)
    update_job_status(job_id, status="completed", progress=100)

    logger.info(
        "Parameter search completed for job %d: %d configs tested, best silhouette=%.4f (%s)",
        job_id, len(configs),
        results[0]["silhouette_score"] if results else -1,
        results[0]["label"] if results else "none",
    )
