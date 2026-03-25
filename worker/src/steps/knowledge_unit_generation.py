"""
Knowledge Unit generation step — converts analyzed clusters into Knowledge Units.

For each cluster with completed analysis (topic_name, intent, summary),
this step creates a Knowledge Unit record and its initial version snapshot.

CTO directive: "This cluster will become a Knowledge Unit."
CTO directive: review_status lifecycle: draft -> reviewed -> approved -> rejected

Input:  Completed cluster_analysis results in RDS (clusters with topic_name, intent, summary)
Output: knowledge_units table populated, knowledge_unit_versions v1 snapshots created
        Job status set to 'completed'
"""

import json
import logging
from concurrent.futures import ThreadPoolExecutor, as_completed
from datetime import datetime, timezone

from src.bedrock_client import generate_embedding
from src.bedrock_llm_client import DEFAULT_MODEL_ID, invoke_claude
from src.db import (get_connection, db_cursor, update_job_status, update_job_step_outputs,
                    link_knowledge_units_to_embedding, update_embedding_status,
                    record_token_usage)

logger = logging.getLogger(__name__)

# Prompt version for knowledge structure extraction
KNOWLEDGE_EXTRACTION_PROMPT_VERSION = "knowledge_extract_v1"


def build_knowledge_extraction_prompt(
    cluster: dict,
    mapping: dict,
    representative_metadata: list[dict],
    column_names: list[str] = None,
    dataset_description: str = "",
    column_descriptions: dict = None,
) -> str:
    """
    Build a prompt for LLM to extract structured knowledge fields from
    representative rows of a cluster.

    Only requests fields where the mapping says '_llm'.
    Includes column role hints so the LLM can distinguish dates, statuses,
    product names, and actual content fields.
    Includes dataset and column descriptions for better context.
    """
    # Build column role context from the mapping configuration
    column_roles = []
    if column_names:
        for field, source in mapping.items():
            if source and source not in ("_llm", "_none") and source.isdigit():
                col_idx = int(source)
                if col_idx < len(column_names):
                    column_roles.append(
                        f"- Column \"{column_names[col_idx]}\" is mapped to the '{field}' field"
                    )

    # Build the context from representative row metadata
    rows_text = ""
    for i, meta in enumerate(representative_metadata[:5], 1):
        rows_text += f"\n--- Row {i} ---\n"
        for key, value in meta.items():
            if value and str(value).strip():
                rows_text += f"{key}: {str(value)[:300]}\n"

    # Determine which fields the LLM needs to generate
    llm_fields = []
    field_instructions = {
        "question": "A natural FAQ-style question that a user would ask about this issue (one sentence)",
        "symptoms": "Observable symptoms, error messages, or surface-level phenomena reported by users (2-3 sentences)",
        "root_cause": "The underlying technical or procedural cause of the issue (1-2 sentences)",
        "resolution": "Step-by-step resolution or recommended action (2-3 sentences)",
        "product": "The product or service name most relevant to this cluster (short string, or null if unclear)",
        "category": "A classification tag for this knowledge (e.g., 'billing', 'technical', 'account')",
    }

    for field, source in mapping.items():
        if source == "_llm":
            llm_fields.append(field)

    if not llm_fields:
        return None

    # Build the JSON schema for requested fields
    json_schema = {}
    instructions = []
    for field in llm_fields:
        json_schema[field] = f"<{field_instructions.get(field, field)}>"
        instructions.append(f"- {field}: {field_instructions.get(field, field)}")

    # Add column role hints if available
    role_section = ""
    if column_roles:
        role_section = f"""
Column role information (these columns are directly mapped and handled separately):
{chr(10).join(column_roles)}

Note: Columns like dates, status codes, and IDs are NOT useful content for extraction.
Focus on descriptive text columns (e.g., subject, description, resolution notes) when extracting fields.
"""

    # Build dataset context section from descriptions
    dataset_context = ""
    if dataset_description:
        dataset_context += f"Dataset context: {dataset_description}\n"
    if column_descriptions:
        col_lines = [f"  - {col}: {desc}" for col, desc in column_descriptions.items() if desc]
        if col_lines:
            dataset_context += "Column descriptions:\n" + "\n".join(col_lines) + "\n"

    return f"""You are a knowledge base engineer. Analyze the following support ticket cluster
and extract structured knowledge fields.
{dataset_context}
Cluster topic: {cluster['topic_name']}
Cluster intent: {cluster['intent']}
Cluster summary: {cluster['summary']}
Cluster size: {cluster['row_count']} tickets
{role_section}
Representative tickets:
{rows_text}

Extract the following fields:
{chr(10).join(instructions)}

Respond ONLY with a JSON object (no markdown, no explanation):
{json.dumps(json_schema, indent=2)}

IMPORTANT:
- Base your extraction ONLY on the provided ticket data
- For 'question', write it as if a customer is asking for help
- For 'symptoms', focus on what the user observes, not the cause
- For 'root_cause', focus on why the issue happens, not the symptoms
- Do NOT use date values, status codes, or ticket IDs as content for any field
- Respond in the same language as the input tickets
- If a field cannot be determined, use null"""


def _resolve_column_value(col_idx: int, column_names: list, meta: dict):
    """Look up a value from metadata by column name (preferred) or index (fallback)."""
    col_name = column_names[col_idx] if column_names and col_idx < len(column_names) else None
    if col_name and col_name in meta:
        return meta[col_name]
    meta_values = list(meta.values())
    return meta_values[col_idx] if col_idx < len(meta_values) else None


def _check_field_quality(
    field: str, result: dict, col_idx: int, column_names: list,
    representative_metadata: list, min_length: int = 20, min_coverage: float = 0.4,
) -> bool:
    """Return True if a direct-mapped field value is low quality and should fall back to LLM."""
    col_name = column_names[col_idx] if column_names and col_idx < len(column_names) else None
    total = min(5, len(representative_metadata))
    non_empty = sum(
        1 for meta in representative_metadata[:5]
        if col_name and col_name in meta and meta[col_name] and str(meta[col_name]).strip()
    )
    coverage = non_empty / total if total > 0 else 0
    val = result.get(field)
    return not val or len(val.strip()) < min_length or coverage < min_coverage


def extract_knowledge_fields(
    cluster: dict,
    mapping: dict,
    representative_metadata: list[dict],
    llm_model_id: str,
    column_names: list[str] = None,
    llm_fallback: bool = True,
    dataset_description: str = "",
    column_descriptions: dict = None,
) -> dict:
    """
    Extract knowledge fields using LLM or direct column mapping.

    When llm_fallback is True, direct-mapped fields with low-quality values
    (short text or low coverage across representative rows) are automatically
    re-generated using LLM.

    Returns a dict with keys: question, symptoms, root_cause, resolution, product, category.
    """
    result = {
        "question": None,
        "symptoms": None,
        "root_cause": None,
        "resolution": None,
        "product": None,
        "category": None,
    }

    # Direct column mappings: aggregate values from representative rows
    for field, source in mapping.items():
        if source and source not in ("_llm", "_none") and source.isdigit():
            col_idx = int(source)
            values = set()
            for meta in representative_metadata[:5]:
                val = _resolve_column_value(col_idx, column_names, meta)
                if val and str(val).strip():
                    values.add(str(val).strip())
            if values:
                result[field] = "; ".join(list(values)[:3])

    # Quality check: detect low-quality direct-mapped values and fallback to LLM
    fallback_fields = []
    if llm_fallback:
        for field, source in mapping.items():
            if source and source not in ("_llm", "_none") and source.isdigit():
                if _check_field_quality(field, result, int(source), column_names, representative_metadata):
                    val = result.get(field)
                    logger.info(
                        "Cluster %d: field '%s' low quality (len=%d), falling back to LLM",
                        cluster["id"], field, len(val.strip()) if val else 0,
                    )
                    fallback_fields.append(field)
                    result[field] = None

    # Combine explicitly-configured LLM fields with fallback fields
    llm_fields = [f for f, s in mapping.items() if s == "_llm"]
    all_llm_fields = list(dict.fromkeys(llm_fields + fallback_fields))

    if all_llm_fields:
        # Temporarily override mapping so prompt builder includes fallback fields
        augmented_mapping = dict(mapping)
        for field in fallback_fields:
            augmented_mapping[field] = "_llm"

        prompt = build_knowledge_extraction_prompt(
            cluster, augmented_mapping, representative_metadata, column_names,
            dataset_description=dataset_description,
            column_descriptions=column_descriptions,
        )
        if prompt:
            try:
                llm_result = invoke_claude(prompt, model_id=llm_model_id)
                extracted = llm_result.get("parsed_json")

                # Track token usage for cost aggregation
                result["_input_tokens"] = llm_result.get("input_tokens", 0)
                result["_output_tokens"] = llm_result.get("output_tokens", 0)

                if extracted:
                    for field in all_llm_fields:
                        if field in extracted and extracted[field]:
                            result[field] = extracted[field]
                else:
                    logger.warning(
                        "Cluster %d: LLM knowledge extraction returned non-JSON",
                        cluster["id"],
                    )
            except Exception as e:
                logger.error(
                    "Cluster %d: LLM knowledge extraction failed: %s",
                    cluster["id"], e,
                )

    return result


def load_representative_metadata(cluster_id: int) -> list[dict]:
    """
    Load the full metadata_json for representative rows of a cluster.

    This gives access to all CSV columns (not just the embedding text),
    allowing LLM to extract product names, resolution steps, etc.
    """
    with db_cursor() as cur:
        cur.execute(
            """SELECT dr.metadata_json
               FROM cluster_representatives cr
               JOIN dataset_rows dr ON cr.dataset_row_id = dr.id
               WHERE cr.cluster_id = %s
               ORDER BY cr.rank
               LIMIT 10""",
            (cluster_id,),
        )
        return [
            json.loads(row[0]) if isinstance(row[0], str) else row[0]
            for row in cur.fetchall() if row[0]
        ]


def load_analyzed_clusters(job_id: int) -> list[dict]:
    """
    Load clusters that have completed LLM analysis, along with their
    representative texts and centroid vectors.
    """
    with db_cursor() as cur:
        # Fetch clusters with analysis results
        cur.execute(
            """SELECT c.id, c.cluster_label, c.topic_name, c.intent,
                      c.summary, c.row_count, c.tenant_id
               FROM clusters c
               WHERE c.pipeline_job_id = %s AND c.topic_name IS NOT NULL
               ORDER BY c.cluster_label""",
            (job_id,),
        )
        clusters = [
            {"id": r[0], "cluster_label": r[1], "topic_name": r[2], "intent": r[3],
             "summary": r[4], "row_count": r[5], "tenant_id": r[6]}
            for r in cur.fetchall()
        ]

        # Load representative texts, keywords, and centroids for each cluster
        for cluster in clusters:
            cid = cluster["id"]

            cur.execute(
                """SELECT cr.dataset_row_id, dr.raw_text
                   FROM cluster_representatives cr
                   JOIN dataset_rows dr ON cr.dataset_row_id = dr.id
                   WHERE cr.cluster_id = %s ORDER BY cr.rank LIMIT 10""",
                (cid,),
            )
            cluster["representative_rows"] = [
                {"row_id": r[0], "text": r[1]} for r in cur.fetchall()
            ]

            cur.execute(
                """SELECT response_json FROM cluster_analysis_logs
                   WHERE cluster_id = %s AND pipeline_job_id = %s
                   ORDER BY created_at DESC LIMIT 1""",
                (cid, job_id),
            )
            log_row = cur.fetchone()
            if log_row and log_row[0]:
                analysis = json.loads(log_row[0]) if isinstance(log_row[0], str) else log_row[0]
                cluster["keywords"] = analysis.get("keywords", [])
                cluster["language"] = analysis.get("language", "en")
            else:
                cluster["keywords"] = []
                cluster["language"] = "en"

            cur.execute(
                "SELECT centroid_vector FROM cluster_centroids WHERE cluster_id = %s",
                (cid,),
            )
            centroid_row = cur.fetchone()
            cluster["centroid_vector"] = centroid_row[0] if centroid_row else None

        return clusters


def create_knowledge_unit(
    cluster: dict,
    job_id: int,
    dataset_id: int,
    pipeline_config: dict,
    knowledge_fields: dict = None,
    search_embedding: list = None,
) -> int:
    """
    Insert a Knowledge Unit record and its initial version snapshot.

    Args:
        cluster: Cluster data with topic_name, intent, summary, etc.
        job_id: Pipeline job ID.
        dataset_id: Dataset ID.
        pipeline_config: Pipeline configuration dict.
        knowledge_fields: Extracted knowledge structure fields (question, symptoms, etc.)
        search_embedding: Vector embedding of the question field for retrieval.

    Returns the new knowledge_unit id.
    """
    now = datetime.now(timezone.utc)
    kf = knowledge_fields or {}

    source_refs = {
        "cluster_label": cluster["cluster_label"],
        "representative_row_ids": [r["row_id"] for r in cluster["representative_rows"]],
    }
    typical_cases = [r["text"][:300] for r in cluster["representative_rows"][:5]]

    with db_cursor() as cur:
        cur.execute(
            """INSERT INTO knowledge_units
               (tenant_id, dataset_id, pipeline_job_id, cluster_id,
                topic, intent, summary, question, symptoms,
                root_cause, product, category,
                typical_cases_json, cause_summary, resolution_summary,
                representative_rows_json, keywords_json,
                row_count, confidence, review_status,
                source_refs_json, pipeline_config_version, prompt_version,
                version, embedding, search_embedding, created_at, updated_at)
               VALUES (%s, %s, %s, %s,
                       %s, %s, %s, %s, %s,
                       %s, %s, %s,
                       %s, %s, %s,
                       %s, %s,
                       %s, %s, %s,
                       %s, %s, %s,
                       %s, %s, %s, %s, %s)
               RETURNING id""",
            (
                cluster["tenant_id"], dataset_id, job_id, cluster["id"],
                cluster["topic_name"], cluster["intent"], cluster["summary"],
                kf.get("question"), kf.get("symptoms"),
                kf.get("root_cause"), kf.get("product"), kf.get("category"),
                json.dumps(typical_cases),
                kf.get("root_cause") or None, kf.get("resolution") or None,
                json.dumps(cluster["representative_rows"]), json.dumps(cluster["keywords"]),
                cluster["row_count"], 0.0, "draft",
                json.dumps(source_refs), pipeline_config.get("phase", "2"),
                KNOWLEDGE_EXTRACTION_PROMPT_VERSION,
                1, cluster["centroid_vector"],
                str(search_embedding) if search_embedding else None,
                now, now,
            ),
        )
        ku_id = cur.fetchone()[0]

        # Create initial version snapshot
        snapshot = {
            "topic": cluster["topic_name"], "intent": cluster["intent"],
            "summary": cluster["summary"],
            "question": kf.get("question"), "symptoms": kf.get("symptoms"),
            "root_cause": kf.get("root_cause"), "resolution": kf.get("resolution"),
            "product": kf.get("product"), "category": kf.get("category"),
            "keywords": cluster["keywords"], "row_count": cluster["row_count"],
            "representative_rows": cluster["representative_rows"],
            "review_status": "draft",
        }
        cur.execute(
            """INSERT INTO knowledge_unit_versions
               (knowledge_unit_id, version, snapshot_json, created_at)
               VALUES (%s, %s, %s, %s)""",
            (ku_id, 1, json.dumps(snapshot), now),
        )
        return ku_id


def execute(job_id: int, tenant_id: int, dataset_id: int = None, **kwargs):
    """
    Execute the knowledge unit generation step.

    For each analyzed cluster:
    1. Load cluster analysis results (topic, intent, summary, keywords)
    2. Load representative rows and centroid vector
    3. Create knowledge_unit record with review_status='draft'
    4. Create knowledge_unit_versions v1 snapshot
    5. Set job status to 'completed'
    """
    logger.info("Knowledge unit generation started for job %d", job_id)
    update_job_status(job_id, status="knowledge_unit_generation", progress=10)

    pipeline_config = kwargs.get("pipeline_config") or {}

    # Step 1: Load analyzed clusters
    clusters = load_analyzed_clusters(job_id)
    logger.info("Loaded %d analyzed clusters for KU generation", len(clusters))

    if not clusters:
        update_job_status(
            job_id, status="failed",
            error_detail="No analyzed clusters found for KU generation",
        )
        return

    # Step 2: Extract knowledge fields and create Knowledge Units
    knowledge_mapping = pipeline_config.get("knowledge_mapping", {})
    column_names = pipeline_config.get("column_names", [])
    llm_fallback = pipeline_config.get("llm_fallback", True)
    dataset_description = pipeline_config.get("dataset_description", "")
    column_descriptions = pipeline_config.get("column_descriptions", {})
    llm_model_id = pipeline_config.get("llm_model_id") or DEFAULT_MODEL_ID
    has_llm_fields = any(v == "_llm" for v in knowledge_mapping.values())

    if has_llm_fields:
        logger.info(
            "Knowledge mapping has LLM fields: %s (model: %s)",
            [f for f, s in knowledge_mapping.items() if s == "_llm"],
            llm_model_id,
        )
    else:
        logger.info("No LLM extraction needed — using direct column mappings only")

    ku_ids = []
    total_ku_input_tokens = 0
    total_ku_output_tokens = 0
    for i, cluster in enumerate(clusters):
        progress = 10 + int((i / len(clusters)) * 70)
        update_job_status(job_id, status="knowledge_unit_generation", progress=progress)

        # Load full metadata for representative rows
        rep_metadata = load_representative_metadata(cluster["id"])

        # Extract knowledge fields (LLM + column mapping)
        knowledge_fields = {}
        if knowledge_mapping:
            knowledge_fields = extract_knowledge_fields(
                cluster, knowledge_mapping, rep_metadata, llm_model_id,
                column_names=column_names,
                llm_fallback=llm_fallback,
                dataset_description=dataset_description,
                column_descriptions=column_descriptions,
            )
            # Accumulate token usage from LLM calls
            total_ku_input_tokens += knowledge_fields.pop("_input_tokens", 0)
            total_ku_output_tokens += knowledge_fields.pop("_output_tokens", 0)
            logger.info(
                "Cluster %d knowledge fields: question=%s, symptoms=%s, product=%s",
                cluster["id"],
                "yes" if knowledge_fields.get("question") else "no",
                "yes" if knowledge_fields.get("symptoms") else "no",
                knowledge_fields.get("product", "N/A"),
            )

        # Generate search_embedding from question field
        search_embedding = None
        question = knowledge_fields.get("question")
        if question:
            try:
                search_embedding = generate_embedding(question)
                logger.info("Generated search embedding for cluster %d", cluster["id"])
            except Exception as e:
                logger.warning("Failed to generate search embedding for cluster %d: %s", cluster["id"], e)

        ku_id = create_knowledge_unit(
            cluster, job_id, dataset_id, pipeline_config,
            knowledge_fields=knowledge_fields,
            search_embedding=search_embedding,
        )
        ku_ids.append(ku_id)

        logger.info(
            "Created KU #%d from cluster %d (topic='%s', rows=%d)",
            ku_id, cluster["cluster_label"],
            cluster["topic_name"], cluster["row_count"],
        )

    # Record aggregated token usage for cost tracking
    if total_ku_input_tokens > 0 or total_ku_output_tokens > 0:
        record_token_usage(
            tenant_id, "knowledge_extraction", llm_model_id,
            total_ku_input_tokens, total_ku_output_tokens,
        )

    # Step 3: Link KUs to embedding and mark embedding as ready
    pipeline_config = kwargs.get("pipeline_config") or {}
    embedding_id = pipeline_config.get("embedding_id")
    if embedding_id:
        link_knowledge_units_to_embedding(job_id, embedding_id)
        update_embedding_status(embedding_id, "ready", row_count=len(ku_ids))

    # Step 4: Record step metadata
    update_job_step_outputs(job_id, "knowledge_unit_generation", {
        "knowledge_units_created": len(ku_ids),
        "knowledge_unit_ids": ku_ids,
    })

    # Step 5: Mark job as completed (this is the final step)
    update_job_status(job_id, status="completed", progress=100)

    logger.info(
        "Knowledge unit generation completed for job %d: %d KUs created",
        job_id, len(ku_ids),
    )
