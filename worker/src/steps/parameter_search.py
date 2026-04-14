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

Auto-add top-N: when pipeline_config has `auto_add_top_n=N`, after the sweep
finishes the step also materialises the N best sweep results (plus any
user-entered configs carried via `user_clustering_configs`) as queued
clustering-only follow-up jobs. This is how the dataset wizard's "Search
parameters and auto-add top patterns" button works end-to-end.
"""

import json
import logging
from datetime import datetime, timezone

import numpy as np

from src.db import db_cursor, update_job_status, update_job_step_outputs, global_progress
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

    # Step 7: Auto-materialise follow-up clustering jobs.
    # When the wizard ran this with auto_add_top_n, pick the best N sweep
    # results plus any user-entered patterns and create clustering-only
    # PipelineJob rows pointing back at this job as the embedding source.
    auto_add = pipeline_config.get("auto_add_top_n")
    user_configs = pipeline_config.get("user_clustering_configs") or []
    if auto_add and int(auto_add) > 0:
        _create_follow_up_clustering_jobs(
            parent_job_id=job_id,
            tenant_id=tenant_id,
            dataset_id=dataset_id,
            base_config=pipeline_config,
            sweep_results=results,
            top_n=int(auto_add),
            user_configs=user_configs,
        )


# ---------------------------------------------------------------------------
# Follow-up job materialisation (wizard "auto-add top patterns" flow)
# ---------------------------------------------------------------------------

# Which sweep-result params belong to which method. The Python worker mirrors
# the Laravel clustering_configs form convention (method-prefixed keys) so the
# downstream clustering step receives familiar inputs.
_METHOD_PARAM_KEYS = {
    "leiden": {
        "resolution": "leiden_resolution",
        "n_neighbors": "leiden_n_neighbors",
    },
    "hdbscan": {
        "min_cluster_size": "hdbscan_min_cluster_size",
        "min_samples": "hdbscan_min_samples",
    },
    "kmeans": {
        "n_clusters": "kmeans_n_clusters",
    },
    "agglomerative": {
        "n_clusters": "agglomerative_n_clusters",
        "linkage": "agglomerative_linkage",
    },
}


def _sweep_result_to_clustering_params(method: str, sweep_params: dict) -> dict:
    """
    Normalise a sweep result's bare params dict into the method-prefixed
    dict shape the clustering step expects (matches Laravel wizard form).
    """
    key_map = _METHOD_PARAM_KEYS.get(method, {})
    out = {}
    for src_key, prefixed_key in key_map.items():
        if src_key in sweep_params:
            out[prefixed_key] = sweep_params[src_key]
    return out


def _user_config_to_clustering_params(user_config: dict) -> dict:
    """
    Extract the prefixed param keys (hdbscan_*, kmeans_*, leiden_*,
    agglomerative_*) from a user-entered clustering_configs entry, filtering
    out empty values. Mirrors DatasetWizardController::finalize's array_filter.
    """
    method = user_config.get("method", "hdbscan")
    all_keys = set()
    for keymap in _METHOD_PARAM_KEYS.values():
        all_keys.update(keymap.values())

    out = {}
    for k in all_keys:
        v = user_config.get(k)
        if v is None or v == "":
            continue
        out[k] = v
    return out


def _create_follow_up_clustering_jobs(
    parent_job_id: int,
    tenant_id: int,
    dataset_id: int,
    base_config: dict,
    sweep_results: list,
    top_n: int,
    user_configs: list,
):
    """
    Create clustering-only PipelineJob rows for (user_configs + top-N sweep
    results) in a single transaction, then kick off the first queued one.

    Each new job:
      - start_step = 'clustering'
      - source_job_id = parent_job_id (parameter_search job, holds embedding)
      - status = 'queued'
      - pipeline_config = base_config minus wizard-only flags, plus
        clustering_method + clustering_params for this pattern.

    Uses dispatch_queued_job() to hand off to the existing queue processor.
    """
    # Build the list of pattern specs to materialise: user-entered first
    # (so they appear in the sidebar in the order the user typed them),
    # then auto-picked top-N by silhouette.
    patterns: list[tuple[str, dict, str]] = []

    for cfg in user_configs:
        method = cfg.get("method", "hdbscan")
        params = _user_config_to_clustering_params(cfg)
        label = f"user: {method}"
        patterns.append((method, params, label))

    # Filter out clearly failed sweep results (silhouette == -1) before
    # picking the top-N, otherwise we might queue a run of "n_clusters=0".
    valid_sweep = [r for r in sweep_results if r.get("silhouette_score", -1) > -1]
    for r in valid_sweep[:top_n]:
        method = r["method"]
        params = _sweep_result_to_clustering_params(method, r.get("params", {}))
        label = f"auto: {r.get('label', method)}"
        patterns.append((method, params, label))

    if not patterns:
        logger.info(
            "auto_add_top_n requested for job %d but no patterns to materialise",
            parent_job_id,
        )
        return

    # Strip wizard-only flags so they don't recurse into the follow-ups.
    child_base = {
        k: v for k, v in base_config.items()
        if k not in ("post_embedding_action", "auto_add_top_n", "user_clustering_configs")
    }

    now = datetime.now(timezone.utc)
    created_job_ids = []
    with db_cursor(tenant_id=tenant_id) as cur:
        for method, params, label in patterns:
            child_config = dict(child_base)
            child_config["clustering_method"] = method
            child_config["clustering_params"] = params
            # Carry the language-bias flag as it was on the parent.
            child_config.setdefault(
                "remove_language_bias",
                base_config.get("remove_language_bias", True),
            )

            cur.execute(
                """INSERT INTO pipeline_jobs
                   (workspace_id, dataset_id, start_step, source_job_id,
                    status, progress, pipeline_config_snapshot_json,
                    created_at, updated_at)
                   VALUES (%s, %s, 'clustering', %s, 'queued', 0, %s, %s, %s)
                   RETURNING id""",
                (tenant_id, dataset_id, parent_job_id,
                 json.dumps(child_config), now, now),
            )
            new_id = cur.fetchone()[0]
            created_job_ids.append(new_id)
            logger.info(
                "Queued follow-up clustering job %d (%s) from parent %d",
                new_id, label, parent_job_id,
            )

    logger.info(
        "Auto-added %d clustering jobs for parent %d: %s",
        len(created_job_ids), parent_job_id, created_job_ids,
    )

    # Kick off the first queued job. dispatch_queued_job scans the workspace
    # for the oldest 'queued' row and sends it to SQS; subsequent jobs are
    # fired from dispatch_next_step at pipeline completion (existing logic).
    try:
        from src.step_chain import dispatch_queued_job
        dispatch_queued_job(tenant_id)
    except Exception as exc:
        # Non-fatal: the queue scan is idempotent, so a later completion of
        # any pipeline will pick these up even if this call failed.
        logger.warning(
            "dispatch_queued_job failed after auto-adding follow-ups: %s", exc,
        )
