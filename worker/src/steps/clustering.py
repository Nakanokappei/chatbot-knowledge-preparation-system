"""
Clustering step — group embeddings using configurable algorithms.

Supports four clustering methods (BERTopic-inspired design):
  - hdbscan:       Density-based, automatic cluster count, noise detection
  - kmeans:        Spherical clusters, fixed count, no noise
  - agglomerative: Hierarchical, fixed count, no noise
  - leiden:        Graph-based community detection via HNSW k-NN + Leiden

CTO directive: "This cluster will become a Knowledge Unit later."
Every design decision here serves that downstream purpose.

Input:  s3://{bucket}/{tenant_id}/jobs/{job_id}/embedding/embeddings.npy
Output: clusters, cluster_memberships, cluster_centroids, cluster_representatives in RDS
        + s3://{bucket}/{tenant_id}/jobs/{job_id}/clustering/cluster_results.json
"""

import io
import json
import logging
from datetime import datetime, timezone

import boto3
import numpy as np
from sklearn.metrics import silhouette_score

from src.config import S3_BUCKET, S3_REGION
from src.db import get_connection, update_job_status, update_job_step_outputs, link_clusters_to_embedding, global_progress, update_job_action
from src.step_chain import dispatch_next_step

logger = logging.getLogger(__name__)

# Default parameters for each clustering method
DEFAULT_PARAMS = {
    "hdbscan": {
        "min_cluster_size": 15,
        "min_samples": 5,
        "metric": "euclidean",
        "cluster_selection_method": "eom",
    },
    "kmeans": {
        "n_clusters": 10,
        "n_init": 10,
        "max_iter": 300,
        "random_state": 42,
    },
    "agglomerative": {
        "n_clusters": 10,
        "linkage": "ward",
    },
    "leiden": {
        "n_neighbors": 15,
        "resolution": 1.0,
        "ef_construction": 200,
        "M": 16,
    },
}

# Number of representative rows per cluster (closest to centroid)
NUM_REPRESENTATIVES = 5


# ---------------------------------------------------------------------------
# Language direction removal (multilingual debiasing)
# ---------------------------------------------------------------------------

def detect_languages(row_ids: list[int]) -> dict[int, str]:
    """
    Detect the language of each row by inspecting raw_text from the DB.

    Uses a fast heuristic: character script distribution (CJK, Cyrillic,
    Arabic, Latin, etc.) to classify without external libraries.
    Returns a dict mapping row_id -> ISO 639-1 language code.
    """
    import unicodedata

    conn = get_connection()
    try:
        with conn.cursor() as cur:
            placeholders = ",".join(["%s"] * len(row_ids))
            cur.execute(
                f"SELECT id, raw_text FROM dataset_rows WHERE id IN ({placeholders})",
                row_ids,
            )
            rows = cur.fetchall()
    finally:
        conn.close()

    lang_map = {}
    # Classify each row's language by its dominant Unicode script category
    for row_id, text in rows:
        # Count script categories in the first 200 characters
        sample = (text or "")[:200]
        scripts = {"CJK": 0, "HANGUL": 0, "CYRILLIC": 0, "ARABIC": 0, "LATIN": 0, "OTHER": 0}

        # Tally script categories from the first 200 characters
        for character in sample:
            if character.isspace() or character.isdigit():
                continue
            name = unicodedata.name(character, "UNKNOWN").upper()
            if "CJK" in name or "HIRAGANA" in name or "KATAKANA" in name:
                scripts["CJK"] += 1
            elif "HANGUL" in name:
                scripts["HANGUL"] += 1
            elif "CYRILLIC" in name:
                scripts["CYRILLIC"] += 1
            elif "ARABIC" in name:
                scripts["ARABIC"] += 1
            elif "LATIN" in name:
                scripts["LATIN"] += 1
            else:
                scripts["OTHER"] += 1

        # Pick dominant script
        dominant = max(scripts, key=scripts.get)
        lang_code = {
            "CJK": "ja",
            "HANGUL": "ko",
            "CYRILLIC": "ru",
            "ARABIC": "ar",
            "LATIN": "en",
            "OTHER": "en",
        }.get(dominant, "en")

        lang_map[row_id] = lang_code

    return lang_map


def remove_language_direction(
    embeddings: np.ndarray,
    row_ids: list[int],
) -> tuple[np.ndarray, dict]:
    """
    Remove language bias from embedding vectors.

    For each language group, computes the mean vector (language direction),
    then subtracts it from every vector in that group. Finally, all vectors
    are re-normalized to unit length for cosine-based methods.

    Returns (debiased_embeddings, language_stats).
    """
    # Detect languages
    lang_map = detect_languages(row_ids)

    # Group indices by language
    lang_groups: dict[str, list[int]] = {}
    for i, row_id in enumerate(row_ids):
        lang = lang_map.get(row_id, "en")
        lang_groups.setdefault(lang, []).append(i)

    # Log language distribution
    lang_stats = {lang: len(indices) for lang, indices in lang_groups.items()}
    logger.info("Language distribution: %s", lang_stats)

    # Debiasing is unnecessary when all rows share the same language
    if len(lang_groups) <= 1:
        logger.info("Single language detected, skipping language direction removal")
        return embeddings, lang_stats

    # Compute global mean
    global_mean = embeddings.mean(axis=0)

    # Debias each language group: subtract its mean direction, restore global mean
    debiased = embeddings.copy()
    for lang, indices in lang_groups.items():
        idx_array = np.array(indices)
        lang_mean = embeddings[idx_array].mean(axis=0)
        debiased[idx_array] = debiased[idx_array] - lang_mean + global_mean

    # Re-normalize to unit length (important for cosine similarity)
    norms = np.linalg.norm(debiased, axis=1, keepdims=True)
    norms = np.where(norms == 0, 1, norms)  # Avoid division by zero
    debiased = debiased / norms

    logger.info(
        "Language direction removed for %d languages across %d vectors",
        len(lang_groups), len(embeddings),
    )

    return debiased, lang_stats


# ---------------------------------------------------------------------------
# Clustering algorithm implementations
# ---------------------------------------------------------------------------

def run_hdbscan(embeddings: np.ndarray, params: dict) -> tuple[np.ndarray, np.ndarray]:
    """
    Run HDBSCAN density-based clustering.

    Returns (labels, probabilities). Noise points have label -1.
    """
    import hdbscan

    logger.info(
        "Running HDBSCAN (min_cluster_size=%d, min_samples=%d) on %d vectors",
        params["min_cluster_size"], params["min_samples"], len(embeddings),
    )

    clusterer = hdbscan.HDBSCAN(**params)
    clusterer.fit(embeddings)

    labels = clusterer.labels_
    probabilities = clusterer.probabilities_

    return labels, probabilities


def run_kmeans(embeddings: np.ndarray, params: dict) -> tuple[np.ndarray, np.ndarray]:
    """
    Run K-Means clustering.

    All points are assigned to a cluster (no noise). Probabilities are set
    to 1.0 for all points since K-Means is a hard assignment algorithm.
    """
    from sklearn.cluster import KMeans

    n_clusters = params["n_clusters"]
    logger.info("Running K-Means (n_clusters=%d) on %d vectors", n_clusters, len(embeddings))

    clusterer = KMeans(
        n_clusters=n_clusters,
        n_init=params.get("n_init", 10),
        max_iter=params.get("max_iter", 300),
        random_state=params.get("random_state", 42),
    )
    labels = clusterer.fit_predict(embeddings)

    # K-Means is hard assignment: every point belongs to exactly one cluster
    probabilities = np.ones(len(labels), dtype=np.float64)

    return labels, probabilities


def run_agglomerative(embeddings: np.ndarray, params: dict) -> tuple[np.ndarray, np.ndarray]:
    """
    Run Agglomerative (hierarchical) clustering.

    Builds a bottom-up hierarchy and cuts at the specified number of clusters.
    All points are assigned (no noise).
    """
    from sklearn.cluster import AgglomerativeClustering

    n_clusters = params["n_clusters"]
    linkage = params.get("linkage", "ward")
    logger.info(
        "Running Agglomerative (n_clusters=%d, linkage=%s) on %d vectors",
        n_clusters, linkage, len(embeddings),
    )

    clusterer = AgglomerativeClustering(n_clusters=n_clusters, linkage=linkage)
    labels = clusterer.fit_predict(embeddings)

    # Agglomerative is hard assignment
    probabilities = np.ones(len(labels), dtype=np.float64)

    return labels, probabilities


def run_leiden(embeddings: np.ndarray, params: dict) -> tuple[np.ndarray, np.ndarray]:
    """
    Run HNSW + Leiden graph-based community detection.

    Steps:
    1. Build a k-nearest-neighbor graph using HNSW (hnswlib)
    2. Convert to igraph weighted graph (cosine similarity as edge weights)
    3. Run Leiden community detection algorithm

    This approach excels at finding natural community structure in
    high-dimensional embedding spaces without requiring a predefined
    cluster count.
    """
    import hnswlib
    import igraph as ig
    import leidenalg as la

    n_neighbors = params.get("n_neighbors", 15)
    resolution = params.get("resolution", 1.0)
    ef_construction = params.get("ef_construction", 200)
    M = params.get("M", 16)

    n_points, dim = embeddings.shape
    logger.info(
        "Running Leiden (n_neighbors=%d, resolution=%.2f) on %d vectors (dim=%d)",
        n_neighbors, resolution, n_points, dim,
    )

    # Step 1: Build HNSW index for fast approximate k-NN search
    logger.info("Building HNSW index (ef_construction=%d, M=%d)...", ef_construction, M)
    index = hnswlib.Index(space="cosine", dim=dim)
    index.init_index(max_elements=n_points, ef_construction=ef_construction, M=M)
    index.add_items(embeddings, np.arange(n_points))
    index.set_ef(max(n_neighbors * 2, 50))

    # Query k nearest neighbors for each point
    neighbor_indices, neighbor_distances = index.knn_query(embeddings, k=n_neighbors)

    # Step 2: Build weighted igraph from k-NN results
    logger.info("Building k-NN graph (%d neighbors per node)...", n_neighbors)
    edges = []
    weights = []

    # Convert k-NN results to edge list with cosine similarity weights
    for i in range(n_points):
        for j_idx in range(n_neighbors):
            j = int(neighbor_indices[i][j_idx])
            if i == j:
                continue  # Skip self-loops

            # Cosine distance → cosine similarity as edge weight
            cosine_dist = float(neighbor_distances[i][j_idx])
            similarity = max(1.0 - cosine_dist, 0.0)

            edges.append((i, j))
            weights.append(similarity)

    graph = ig.Graph(n=n_points, edges=edges, directed=False)
    graph.es["weight"] = weights

    # Remove duplicate edges (keep highest weight)
    graph.simplify(combine_edges="max")

    logger.info(
        "Graph built: %d vertices, %d edges",
        graph.vcount(), graph.ecount(),
    )

    # Step 3: Run Leiden community detection
    logger.info("Running Leiden algorithm (resolution=%.2f)...", resolution)
    partition = la.find_partition(
        graph,
        la.RBConfigurationVertexPartition,
        weights="weight",
        resolution_parameter=resolution,
    )

    labels = np.array(partition.membership, dtype=np.intp)

    # Leiden is hard assignment: all points belong to a community
    probabilities = np.ones(len(labels), dtype=np.float64)

    return labels, probabilities


# ---------------------------------------------------------------------------
# Dispatcher: select and run the appropriate clustering method
# ---------------------------------------------------------------------------

CLUSTERING_METHODS = {
    "hdbscan": run_hdbscan,
    "kmeans": run_kmeans,
    "agglomerative": run_agglomerative,
    "leiden": run_leiden,
}


def run_clustering(
    embeddings: np.ndarray,
    method: str = "hdbscan",
    params: dict | None = None,
) -> tuple[np.ndarray, np.ndarray, str, dict]:
    """
    Dispatch to the selected clustering algorithm.

    Args:
        embeddings: (N, D) float array of embedding vectors.
        method: One of "hdbscan", "kmeans", "agglomerative", "leiden".
        params: Algorithm-specific parameters (merged with defaults).

    Returns:
        (labels, probabilities, method_name, effective_params) tuple.
    """
    # Validate the requested clustering method before proceeding
    if method not in CLUSTERING_METHODS:
        raise ValueError(
            f"Unknown clustering method '{method}'. "
            f"Supported: {list(CLUSTERING_METHODS.keys())}"
        )

    # Merge user params over defaults.
    # Form params arrive with method prefix (e.g. "hdbscan_min_cluster_size"),
    # so strip the prefix and keep only params relevant to the selected method.
    effective_params = dict(DEFAULT_PARAMS.get(method, {}))
    if params:
        # Strip method-specific prefixes (e.g., "hdbscan_min_cluster_size" -> "min_cluster_size")
        prefix = method + "_"
        cleaned = {}
        for key, value in params.items():
            if key.startswith(prefix):
                cleaned[key[len(prefix):]] = value
            elif "_" not in key or not any(key.startswith(m + "_") for m in CLUSTERING_METHODS):
                # Keep params without a method prefix (generic params)
                cleaned[key] = value
        # Cast numeric strings from form inputs to int/float for sklearn
        for key, value in cleaned.items():
            if isinstance(value, str):
                try:
                    cleaned[key] = int(value)
                except ValueError:
                    try:
                        cleaned[key] = float(value)
                    except ValueError:
                        pass
        effective_params.update(cleaned)

    logger.info("Clustering method: %s, params: %s", method, effective_params)

    # Run the selected algorithm
    run_fn = CLUSTERING_METHODS[method]
    labels, probabilities = run_fn(embeddings, effective_params)

    # Log summary
    n_clusters = len(set(labels)) - (1 if -1 in labels else 0)
    n_noise = int((labels == -1).sum())
    logger.info(
        "%s result: %d clusters, %d noise points (%.1f%%)",
        method.upper(), n_clusters, n_noise,
        n_noise / len(labels) * 100 if len(labels) > 0 else 0,
    )

    return labels, probabilities, method, effective_params


# ---------------------------------------------------------------------------
# Shared utility functions (unchanged)
# ---------------------------------------------------------------------------

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


def compute_centroids(embeddings: np.ndarray, labels: np.ndarray) -> dict[int, np.ndarray]:
    """
    Compute the centroid (mean vector) for each cluster.

    Noise points (label=-1) are excluded.
    Returns a dict: cluster_label -> centroid vector.
    """
    centroids = {}
    unique_labels = set(labels)

    # Compute mean vector for each non-noise cluster
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

    # For each cluster, rank members by proximity to the centroid
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
                       (pipeline_job_id, workspace_id, cluster_label, row_count, quality_score, created_at, updated_at)
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


# ---------------------------------------------------------------------------
# Step entry point
# ---------------------------------------------------------------------------

def execute(job_id: int, tenant_id: int, dataset_id: int = None,
            input_s3_path: str = None, pipeline_config: dict = None, **kwargs):
    """
    Execute the clustering step.

    1. Load embeddings from S3
    2. Run selected clustering algorithm (from pipeline_config)
    3. Compute centroids
    4. Find representative rows
    5. Compute quality metrics (silhouette score)
    6. Save everything to RDS
    7. Save results JSON to S3
    8. Chain to next step (cluster_analysis)
    """
    if pipeline_config is None:
        pipeline_config = {}

    logger.info("Clustering step started for job %d", job_id)
    update_job_status(job_id, status="clustering", progress=global_progress("clustering", 10))
    update_job_action(job_id, "クラスタリングを開始しています")

    # Step 1: Load embeddings and row_id mapping
    update_job_action(job_id, "S3から埋め込みベクトルを読み込み中")
    embeddings = download_npy_from_s3(input_s3_path)
    logger.info("Loaded embeddings: shape=%s", embeddings.shape)

    # Load row_ids from the sibling path
    row_ids_path = input_s3_path.replace("embeddings.npy", "row_ids.json")
    row_ids = download_json_from_s3(row_ids_path)
    logger.info("Loaded %d row_ids", len(row_ids))

    update_job_status(job_id, status="clustering", progress=global_progress("clustering", 20))

    # Step 1.5: Language direction removal (optional, enabled by default)
    remove_lang_bias = pipeline_config.get("remove_language_bias", True)
    lang_stats = {}
    if remove_lang_bias:
        logger.info("Applying language direction removal...")
        update_job_action(job_id, "多言語バイアスを除去中")
        embeddings, lang_stats = remove_language_direction(embeddings, row_ids)
        logger.info("Language debiasing complete")
    else:
        logger.info("Language direction removal disabled")

    # Step 2: Run selected clustering algorithm
    clustering_method = pipeline_config.get("clustering_method", "hdbscan")
    clustering_params = pipeline_config.get("clustering_params", {})

    update_job_action(
        job_id,
        f"{clustering_method.upper()} でクラスタリング実行中 ({len(embeddings)}ベクトル)",
    )
    labels, probabilities, method_used, effective_params = run_clustering(
        embeddings, method=clustering_method, params=clustering_params,
    )

    update_job_status(job_id, status="clustering", progress=global_progress("clustering", 40))

    # Step 3: Compute centroids
    update_job_action(job_id, "セントロイドを計算中")
    centroids = compute_centroids(embeddings, labels)
    logger.info("Computed %d cluster centroids", len(centroids))

    update_job_status(job_id, status="clustering", progress=global_progress("clustering", 50))
    update_job_action(job_id, f"代表行を抽出中 ({len(centroids)}クラスタ)")

    # Step 4: Find representative rows
    representatives = find_representatives(embeddings, labels, centroids, row_ids)

    update_job_status(job_id, status="clustering", progress=global_progress("clustering", 60))
    update_job_action(job_id, "品質指標 (silhouette) を計算中")

    # Step 5: Compute quality metrics
    n_clusters = len(centroids)
    n_noise = int((labels == -1).sum())

    # Silhouette score measures cluster separation; requires at least 2 clusters.
    # Two important choices for text embeddings:
    #   - metric='cosine': text embeddings (Bedrock Titan, OpenAI) live on a
    #     unit hypersphere where cosine distance is the semantically meaningful
    #     measure. sklearn defaults to Euclidean, which gives numerically
    #     different (and not directly interpretable) scores even for unit
    #     vectors, and breaks the UI threshold scale of [-1, 1] we display.
    #   - sample_size cap: silhouette_score with metric='cosine' materialises
    #     a full N×N pairwise distance matrix. For the clustering step we run
    #     against the full dataset (potentially 50k+ rows), which would be
    #     ~10GB and OOM the 4GB Fargate worker. 2000 random samples is enough
    #     to get a stable estimate (±0.01 vs full population in practice).
    quality_score = -1.0
    non_noise_mask = labels != -1
    if n_clusters >= 2 and non_noise_mask.sum() > n_clusters:
        try:
            n_eligible = int(non_noise_mask.sum())
            quality_score = float(silhouette_score(
                embeddings[non_noise_mask],
                labels[non_noise_mask],
                metric='cosine',
                sample_size=min(2000, n_eligible),
                random_state=42,
            ))
            logger.info("Silhouette score (cosine): %.4f", quality_score)
        except Exception as e:
            logger.warning("Failed to compute silhouette score: %s", e)

    update_job_status(job_id, status="clustering", progress=global_progress("clustering", 70))
    update_job_action(job_id, f"クラスタをDBに保存中 ({n_clusters}件)")

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

    # Link clusters to their parent embedding
    embedding_id = pipeline_config.get("embedding_id")
    if embedding_id:
        link_clusters_to_embedding(job_id, embedding_id)

    update_job_status(job_id, status="clustering", progress=global_progress("clustering", 85))

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
        "clustering_method": method_used,
        "clustering_params": effective_params,
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

    update_job_status(job_id, status="clustering", progress=global_progress("clustering", 95))

    # Step 8: Record step metadata
    update_job_step_outputs(job_id, "clustering", {
        "n_clusters": n_clusters,
        "n_noise": n_noise,
        "noise_percentage": round(n_noise / len(labels) * 100, 1),
        "silhouette_score": quality_score,
        "clustering_method": method_used,
        "clustering_params": effective_params,
        "remove_language_bias": remove_lang_bias,
        "language_stats": lang_stats,
        "results_s3_path": results_s3_path,
    })

    # Step 9: Chain to next step (cluster_analysis)
    next_step = dispatch_next_step(
        current_step="clustering",
        job_id=job_id,
        tenant_id=tenant_id,
        dataset_id=dataset_id,
        output_s3_path=results_s3_path,
        pipeline_config=pipeline_config,
    )

    if next_step is None:
        update_job_status(job_id, status="completed", progress=100)

    logger.info(
        "Clustering step completed for job %d: method=%s, %d clusters, %d noise, silhouette=%.4f",
        job_id, method_used, n_clusters, n_noise, quality_score,
    )
