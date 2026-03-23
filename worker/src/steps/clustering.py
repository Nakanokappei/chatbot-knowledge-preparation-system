"""
Clustering step — group embeddings using HDBSCAN.

This step reads embedding vectors from S3, runs HDBSCAN clustering, saves
cluster assignments to RDS, computes centroids, and selects representative rows.

CTO directive: "This cluster will become a Knowledge Unit later."
Every design decision here serves that downstream purpose.

Input:  s3://{bucket}/{tenant_id}/jobs/{job_id}/embedding/embeddings.npy
Output: clusters, cluster_memberships, cluster_centroids, cluster_representatives in RDS
        + s3://{bucket}/{tenant_id}/jobs/{job_id}/clustering/cluster_results.json
"""

import io
import json
import logging

import boto3
import hdbscan
import numpy as np
from sklearn.metrics import silhouette_score

from src.config import S3_BUCKET, S3_REGION
from src.db import get_connection, update_job_status, update_job_step_outputs

logger = logging.getLogger(__name__)

# HDBSCAN parameters (CTO decision)
HDBSCAN_PARAMS = {
    "min_cluster_size": 15,
    "min_samples": 5,
    "metric": "euclidean",
    "cluster_selection_method": "eom",
}

# Number of representative rows per cluster (closest to centroid)
NUM_REPRESENTATIVES = 5


def download_npy_from_s3(s3_path: str) -> np.ndarray:
    """Download a NumPy array from S3."""
    s3 = boto3.client("s3", region_name=S3_REGION)
    key = s3_path.replace(f"s3://{S3_BUCKET}/", "")
    response = s3.get_object(Bucket=S3_BUCKET, Key=key)
    buffer = io.BytesIO(response["Body"].read())
    return np.load(buffer)


def download_json_from_s3(s3_path: str):
    """Download and parse a JSON file from S3."""
    s3 = boto3.client("s3", region_name=S3_REGION)
    key = s3_path.replace(f"s3://{S3_BUCKET}/", "")
    response = s3.get_object(Bucket=S3_BUCKET, Key=key)
    return json.loads(response["Body"].read())


def run_hdbscan(embeddings: np.ndarray) -> hdbscan.HDBSCAN:
    """
    Run HDBSCAN clustering on the embedding matrix.

    Returns the fitted HDBSCAN clusterer object.
    """
    logger.info(
        "Running HDBSCAN (min_cluster_size=%d, min_samples=%d) on %d vectors",
        HDBSCAN_PARAMS["min_cluster_size"],
        HDBSCAN_PARAMS["min_samples"],
        len(embeddings),
    )

    clusterer = hdbscan.HDBSCAN(**HDBSCAN_PARAMS)
    clusterer.fit(embeddings)

    labels = clusterer.labels_
    n_clusters = len(set(labels)) - (1 if -1 in labels else 0)
    n_noise = (labels == -1).sum()

    logger.info(
        "HDBSCAN result: %d clusters, %d noise points (%.1f%%)",
        n_clusters, n_noise, n_noise / len(labels) * 100,
    )

    return clusterer


def compute_centroids(embeddings: np.ndarray, labels: np.ndarray) -> dict[int, np.ndarray]:
    """
    Compute the centroid (mean vector) for each cluster.

    Noise points (label=-1) are excluded.
    Returns a dict: cluster_label -> centroid vector.
    """
    centroids = {}
    unique_labels = set(labels)

    for label in unique_labels:
        if label == -1:
            continue  # Skip noise
        mask = labels == label
        cluster_vectors = embeddings[mask]
        centroids[label] = cluster_vectors.mean(axis=0)

    return centroids


def find_representatives(
    embeddings: np.ndarray,
    labels: np.ndarray,
    centroids: dict[int, np.ndarray],
    row_ids: list[int],
    n: int = NUM_REPRESENTATIVES,
) -> dict[int, list[dict]]:
    """
    Find the N rows closest to each cluster centroid.

    Returns a dict: cluster_label -> list of {row_id, distance, rank}.
    These are the "representative rows" for Knowledge Unit generation.
    """
    representatives = {}

    for label, centroid in centroids.items():
        mask = labels == label
        indices = np.where(mask)[0]
        cluster_vectors = embeddings[indices]

        # Compute euclidean distance to centroid
        distances = np.linalg.norm(cluster_vectors - centroid, axis=1)

        # Sort by distance (closest first) and take top N
        sorted_order = np.argsort(distances)[:n]

        reps = []
        for rank, local_idx in enumerate(sorted_order):
            global_idx = indices[local_idx]
            reps.append({
                "row_id": int(row_ids[global_idx]),
                "distance": float(distances[local_idx]),
                "rank": rank + 1,
            })

        representatives[label] = reps

    return representatives


def save_clusters_to_db(
    job_id: int,
    tenant_id: int,
    labels: np.ndarray,
    probabilities: np.ndarray,
    row_ids: list[int],
    centroids: dict[int, np.ndarray],
    representatives: dict[int, list[dict]],
    quality_score: float,
):
    """
    Save all clustering results to RDS:
    - clusters table
    - cluster_memberships table
    - cluster_centroids table
    - cluster_representatives table
    """
    conn = get_connection()
    try:
        with conn.cursor() as cur:
            from datetime import datetime, timezone
            now = datetime.now(timezone.utc)

            # Clear previous clustering results for this job (idempotent re-run)
            cur.execute(
                """DELETE FROM cluster_representatives
                   WHERE cluster_id IN (SELECT id FROM clusters WHERE pipeline_job_id = %s)""",
                (job_id,),
            )
            cur.execute(
                """DELETE FROM cluster_centroids
                   WHERE cluster_id IN (SELECT id FROM clusters WHERE pipeline_job_id = %s)""",
                (job_id,),
            )
            cur.execute(
                """DELETE FROM cluster_memberships
                   WHERE cluster_id IN (SELECT id FROM clusters WHERE pipeline_job_id = %s)""",
                (job_id,),
            )
            cur.execute("DELETE FROM clusters WHERE pipeline_job_id = %s", (job_id,))
            logger.info("Cleared previous clustering data for job %d", job_id)

            unique_labels = sorted(set(labels))

            # Map: cluster_label -> cluster_id (DB primary key)
            label_to_db_id = {}

            # Insert clusters
            for label in unique_labels:
                if label == -1:
                    continue  # Skip noise cluster

                row_count = int((labels == label).sum())

                cur.execute(
                    """INSERT INTO clusters
                       (pipeline_job_id, tenant_id, cluster_label, row_count, quality_score, created_at, updated_at)
                       VALUES (%s, %s, %s, %s, %s, %s, %s)
                       RETURNING id""",
                    (job_id, tenant_id, int(label), row_count, quality_score, now, now),
                )
                cluster_db_id = cur.fetchone()[0]
                label_to_db_id[label] = cluster_db_id

            logger.info("Inserted %d clusters", len(label_to_db_id))

            # Insert cluster_memberships (batch)
            membership_count = 0
            for i, (label, row_id) in enumerate(zip(labels, row_ids)):
                if label == -1:
                    continue  # Skip noise points

                cluster_db_id = label_to_db_id[label]
                prob = float(probabilities[i]) if probabilities is not None else 1.0

                cur.execute(
                    """INSERT INTO cluster_memberships
                       (cluster_id, dataset_row_id, membership_score, created_at, updated_at)
                       VALUES (%s, %s, %s, %s, %s)""",
                    (cluster_db_id, int(row_id), prob, now, now),
                )
                membership_count += 1

            logger.info("Inserted %d cluster_memberships", membership_count)

            # Insert cluster_centroids (pgvector)
            for label, centroid in centroids.items():
                cluster_db_id = label_to_db_id[label]
                vector_str = "[" + ",".join(str(float(v)) for v in centroid) + "]"

                cur.execute(
                    """INSERT INTO cluster_centroids
                       (cluster_id, centroid_vector, created_at, updated_at)
                       VALUES (%s, %s, %s, %s)""",
                    (cluster_db_id, vector_str, now, now),
                )

            logger.info("Inserted %d cluster_centroids", len(centroids))

            # Insert cluster_representatives
            rep_count = 0
            for label, reps in representatives.items():
                cluster_db_id = label_to_db_id[label]
                for rep in reps:
                    cur.execute(
                        """INSERT INTO cluster_representatives
                           (cluster_id, dataset_row_id, distance_to_centroid, rank, created_at, updated_at)
                           VALUES (%s, %s, %s, %s, %s, %s)""",
                        (cluster_db_id, rep["row_id"], rep["distance"], rep["rank"], now, now),
                    )
                    rep_count += 1

            logger.info("Inserted %d cluster_representatives", rep_count)

            conn.commit()

    except Exception:
        conn.rollback()
        raise
    finally:
        conn.close()


def execute(job_id: int, tenant_id: int, dataset_id: int = None,
            input_s3_path: str = None, **kwargs):
    """
    Execute the clustering step.

    1. Load embeddings from S3
    2. Run HDBSCAN
    3. Compute centroids
    4. Find representative rows
    5. Compute quality metrics (silhouette score)
    6. Save everything to RDS
    7. Save results JSON to S3
    8. Mark job as completed (final step in pipeline)
    """
    logger.info("Clustering step started for job %d", job_id)
    update_job_status(job_id, status="clustering", progress=10)

    # Step 1: Load embeddings and row_id mapping
    embeddings = download_npy_from_s3(input_s3_path)
    logger.info("Loaded embeddings: shape=%s", embeddings.shape)

    # Load row_ids from the sibling path
    row_ids_path = input_s3_path.replace("embeddings.npy", "row_ids.json")
    row_ids = download_json_from_s3(row_ids_path)
    logger.info("Loaded %d row_ids", len(row_ids))

    update_job_status(job_id, status="clustering", progress=20)

    # Step 2: Run HDBSCAN
    clusterer = run_hdbscan(embeddings)
    labels = clusterer.labels_
    probabilities = clusterer.probabilities_

    update_job_status(job_id, status="clustering", progress=40)

    # Step 3: Compute centroids
    centroids = compute_centroids(embeddings, labels)
    logger.info("Computed %d cluster centroids", len(centroids))

    update_job_status(job_id, status="clustering", progress=50)

    # Step 4: Find representative rows
    representatives = find_representatives(embeddings, labels, centroids, row_ids)

    update_job_status(job_id, status="clustering", progress=60)

    # Step 5: Compute quality metrics
    n_clusters = len(centroids)
    n_noise = int((labels == -1).sum())

    # Silhouette score requires at least 2 clusters and non-noise points
    quality_score = -1.0
    non_noise_mask = labels != -1
    if n_clusters >= 2 and non_noise_mask.sum() > n_clusters:
        try:
            quality_score = float(silhouette_score(
                embeddings[non_noise_mask],
                labels[non_noise_mask],
            ))
            logger.info("Silhouette score: %.4f", quality_score)
        except Exception as e:
            logger.warning("Failed to compute silhouette score: %s", e)

    update_job_status(job_id, status="clustering", progress=70)

    # Step 6: Save to RDS
    save_clusters_to_db(
        job_id=job_id,
        tenant_id=tenant_id,
        labels=labels,
        probabilities=probabilities,
        row_ids=row_ids,
        centroids=centroids,
        representatives=representatives,
        quality_score=quality_score,
    )

    update_job_status(job_id, status="clustering", progress=85)

    # Step 7: Save results JSON to S3
    cluster_summary = []
    for label in sorted(centroids.keys()):
        count = int((labels == label).sum())
        cluster_summary.append({
            "label": int(label),
            "row_count": count,
            "percentage": round(count / len(labels) * 100, 1),
            "representatives": representatives.get(label, []),
        })

    results = {
        "job_id": job_id,
        "total_rows": len(labels),
        "n_clusters": n_clusters,
        "n_noise": n_noise,
        "noise_percentage": round(n_noise / len(labels) * 100, 1),
        "silhouette_score": quality_score,
        "hdbscan_params": HDBSCAN_PARAMS,
        "clusters": cluster_summary,
    }

    results_s3_path = f"s3://{S3_BUCKET}/{tenant_id}/jobs/{job_id}/clustering/cluster_results.json"
    s3 = boto3.client("s3", region_name=S3_REGION)
    key = results_s3_path.replace(f"s3://{S3_BUCKET}/", "")
    s3.put_object(
        Bucket=S3_BUCKET,
        Key=key,
        Body=json.dumps(results, indent=2),
        ContentType="application/json",
    )

    update_job_status(job_id, status="clustering", progress=95)

    # Step 8: Record step metadata
    update_job_step_outputs(job_id, "clustering", {
        "n_clusters": n_clusters,
        "n_noise": n_noise,
        "noise_percentage": round(n_noise / len(labels) * 100, 1),
        "silhouette_score": quality_score,
        "hdbscan_params": HDBSCAN_PARAMS,
        "results_s3_path": results_s3_path,
    })

    # Step 9: Pipeline complete — mark job as completed
    update_job_status(job_id, status="completed", progress=100)
    logger.info(
        "Clustering step completed for job %d: %d clusters, %d noise, silhouette=%.4f",
        job_id, n_clusters, n_noise, quality_score,
    )
