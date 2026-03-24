# Phase 4 Implementation Plan — Chatbot / RAG Integration

**Date**: 2026-03-24
**Approval**: CTO_Phase3_Completion_Approval_Phase4_Start.md
**Goal**: Knowledge Units → Knowledge Dataset → Vector Retrieval → Chatbot API

---

## 1. Phase 4 Completion Criteria (CTO Defined, 8 items)

| # | Criterion | Day |
|---|-----------|-----|
| 1 | knowledge_datasets table | Day 1 |
| 2 | knowledge_dataset_items table | Day 1 |
| 3 | Dataset Versioning (v1, v2...) | Day 1-2 |
| 4 | Dataset Export JSON (Chatbot format) | Day 2 |
| 5 | Vector Retrieval (similar KU search) | Day 3-4 |
| 6 | Retrieval API (`/api/retrieve`) | Day 4 |
| 7 | Chatbot API (`/api/chat`) | Day 5-6 |
| 8 | Evaluation (retrieval quality) | Day 7-8 |

---

## 2. Existing Infrastructure (Phase 0-3)

| Asset | Details |
|-------|---------|
| `knowledge_units.embedding` | vector(1024), pgvector, Titan Embed v2 |
| `knowledge_units.review_status` | approved KUs are RAG-ready |
| Bedrock Embedding Client | `worker/src/bedrock_client.py`, batch generation, 97% cache hit |
| Bedrock LLM Client | `worker/src/bedrock_llm_client.py`, configurable model |
| Multi-tenant + RLS | 10 tables, `app.tenant_id` session var |
| API Routes | `app/routes/api.php`, Sanctum auth |
| Export (approved-only) | `DashboardController::exportKnowledgeUnits()` |

---

## 3. Implementation Plan

### Day 1: Knowledge Dataset Tables

#### 3.1 Migration: knowledge_datasets

**File**: `app/database/migrations/2026_03_24_200000_create_knowledge_datasets_table.php`

```sql
knowledge_datasets
  id              BIGSERIAL PRIMARY KEY
  tenant_id       BIGINT NOT NULL REFERENCES tenants(id)
  name            VARCHAR(255) NOT NULL
  description     TEXT
  version         INTEGER DEFAULT 1
  status          VARCHAR(50) DEFAULT 'draft'
                  -- draft / published / archived
  source_job_ids  JSONB          -- [{job_id, dataset_name}]
  ku_count        INTEGER DEFAULT 0
  created_by      BIGINT REFERENCES users(id)
  created_at      TIMESTAMP
  updated_at      TIMESTAMP
```

#### 3.2 Migration: knowledge_dataset_items

**File**: `app/database/migrations/2026_03_24_200001_create_knowledge_dataset_items_table.php`

```sql
knowledge_dataset_items
  id                    BIGSERIAL PRIMARY KEY
  knowledge_dataset_id  BIGINT NOT NULL REFERENCES knowledge_datasets(id)
  knowledge_unit_id     BIGINT NOT NULL REFERENCES knowledge_units(id)
  sort_order            INTEGER DEFAULT 0
  included_version      INTEGER NOT NULL   -- KU version at time of inclusion
  created_at            TIMESTAMP

  UNIQUE(knowledge_dataset_id, knowledge_unit_id)
```

#### 3.3 RLS Policies

Both tables get tenant_id RLS policies (same pattern as Phase 3).
knowledge_dataset_items gets indirect isolation via FK to knowledge_datasets.

#### 3.4 Eloquent Models

- `app/app/Models/KnowledgeDataset.php` — BelongsToTenant, hasMany items
- `app/app/Models/KnowledgeDatasetItem.php` — belongsTo dataset + unit

---

### Day 1-2: Dataset Management UI

#### 3.5 KnowledgeDatasetController

**File**: `app/app/Http/Controllers/KnowledgeDatasetController.php`

| Method | Route | Function |
|--------|-------|----------|
| `index()` | `GET /datasets` | Dataset list |
| `create()` | `GET /datasets/create` | Create form (select approved KUs) |
| `store()` | `POST /datasets` | Create dataset from selected KUs |
| `show($dataset)` | `GET /datasets/{dataset}` | Dataset detail + items |
| `publish($dataset)` | `POST /datasets/{dataset}/publish` | draft → published |
| `newVersion($dataset)` | `POST /datasets/{dataset}/new-version` | Create v2 from v1 |

#### 3.6 Dataset Versioning

| Operation | Behavior |
|-----------|----------|
| Create dataset | v1, status=draft |
| Publish | status=published, locked |
| New version | Clone items → v2, status=draft |
| Add/remove KUs | Only on draft datasets |

Published datasets are immutable (chatbot depends on them).

#### 3.7 Views

- `app/resources/views/dashboard/datasets/index.blade.php` — List
- `app/resources/views/dashboard/datasets/create.blade.php` — Select KUs
- `app/resources/views/dashboard/datasets/show.blade.php` — Detail + items

---

### Day 2: Dataset Export JSON

#### 3.8 Export Endpoint

**Route**: `GET /api/datasets/{dataset}/export`

**Format** (Chatbot-optimized):

```json
{
  "dataset_id": 1,
  "name": "Customer Support v1",
  "version": 1,
  "status": "published",
  "exported_at": "2026-03-24T...",
  "knowledge_units": [
    {
      "id": 1,
      "topic": "Password Reset",
      "intent": "Account Access Recovery",
      "summary": "Users requesting password reset...",
      "cause_summary": "Forgotten credentials after...",
      "resolution_summary": "Guide user through reset flow...",
      "keywords": ["password", "reset", "login"],
      "embedding": [0.123, -0.456, ...],  // 1024 dims
      "confidence": 0.95,
      "typical_cases": ["I forgot my password", "Can't log in"],
      "version": 2
    }
  ]
}
```

Embedding included for RAG pre-loading (avoids re-embedding at query time).

---

### Day 3-4: Vector Retrieval

#### 3.9 pgvector Similarity Search

**Core query** (cosine distance):

```sql
SELECT ku.id, ku.topic, ku.intent, ku.summary,
       ku.cause_summary, ku.resolution_summary,
       ku.keywords_json, ku.typical_cases_json,
       ku.confidence,
       1 - (ku.embedding <=> $1::vector) AS similarity
FROM knowledge_units ku
JOIN knowledge_dataset_items kdi ON kdi.knowledge_unit_id = ku.id
WHERE kdi.knowledge_dataset_id = $2
  AND ku.review_status = 'approved'
ORDER BY ku.embedding <=> $1::vector
LIMIT $3
```

#### 3.10 pgvector Index

```sql
CREATE INDEX idx_knowledge_units_embedding_cosine
ON knowledge_units
USING ivfflat (embedding vector_cosine_ops)
WITH (lists = 10);
```

Note: IVFFlat with lists=10 is suitable for < 10,000 KUs.
For larger datasets, consider HNSW index.

#### 3.11 Retrieval Service (Python Worker)

**File**: `worker/src/retrieval.py`

Process:
1. Receive query text
2. Generate embedding via Bedrock Titan Embed v2 (reuse `bedrock_client.py`)
3. Check embedding_cache (same hash logic as embedding step)
4. Execute pgvector similarity search against dataset's KUs
5. Return top-K results with similarity scores

#### 3.12 Retrieval API

**Route**: `POST /api/retrieve`

**Request**:
```json
{
  "query": "How do I reset my password?",
  "dataset_id": 1,
  "top_k": 5,
  "min_similarity": 0.3
}
```

**Response**:
```json
{
  "query": "How do I reset my password?",
  "dataset_id": 1,
  "results": [
    {
      "knowledge_unit_id": 1,
      "topic": "Password Reset",
      "intent": "Account Access Recovery",
      "summary": "...",
      "cause_summary": "...",
      "resolution_summary": "...",
      "similarity": 0.92,
      "confidence": 0.95
    }
  ],
  "embedding_model": "amazon.titan-embed-text-v2:0",
  "latency_ms": 150
}
```

**Implementation**: Laravel controller calls Python retrieval service via internal HTTP or SQS sync pattern.

**Decision needed**: Retrieval path architecture.

Option A: **Laravel direct** — Laravel calls pgvector + Bedrock directly (PHP AWS SDK)
Option B: **Python service** — Separate FastAPI/Flask service for retrieval
Option C: **SQS sync** — Laravel → SQS → Worker → response via polling

**Recommendation**: Option A for Phase 4 (simplest). Migrate to Option B in Phase 5.
Laravel can call Bedrock via AWS SDK for PHP, and pgvector via raw SQL.

---

### Day 5-6: Chatbot API

#### 3.13 Chat Endpoint

**Route**: `POST /api/chat`

**Request**:
```json
{
  "message": "How do I reset my password?",
  "dataset_id": 1,
  "conversation_id": null
}
```

**Process (RAG)**:
1. **Retrieve**: Call `/api/retrieve` internally → top-K KUs
2. **Augment**: Build prompt with KU context
3. **Generate**: Call Bedrock LLM (Claude) with augmented prompt
4. **Return**: Generated response + source KUs

**Response**:
```json
{
  "conversation_id": "conv_abc123",
  "message": "To reset your password, follow these steps...",
  "sources": [
    {"knowledge_unit_id": 1, "topic": "Password Reset", "similarity": 0.92}
  ],
  "model": "anthropic.claude-3-5-haiku-20251001-v1:0",
  "usage": {"input_tokens": 500, "output_tokens": 200}
}
```

#### 3.14 RAG Prompt Template

```
You are a customer support assistant. Answer the user's question
based ONLY on the following knowledge base articles.

If the knowledge base does not contain relevant information,
say "I don't have information about that topic."

## Knowledge Base
{% for ku in knowledge_units %}
### {{ ku.topic }} ({{ ku.intent }})
{{ ku.summary }}
Cause: {{ ku.cause_summary }}
Resolution: {{ ku.resolution_summary }}
{% endfor %}

## User Question
{{ user_message }}

## Instructions
- Answer concisely and helpfully
- Cite which topic(s) you used
- If uncertain, say so
```

#### 3.15 Conversation History (Simple)

**Table**: `chat_conversations`

```sql
chat_conversations
  id              UUID PRIMARY KEY
  tenant_id       BIGINT NOT NULL
  dataset_id      BIGINT NOT NULL
  created_at      TIMESTAMP

chat_messages
  id              BIGSERIAL PRIMARY KEY
  conversation_id UUID REFERENCES chat_conversations(id)
  role            VARCHAR(20)   -- user / assistant
  content         TEXT
  sources_json    JSONB         -- [{ku_id, similarity}]
  tokens_used     INTEGER
  created_at      TIMESTAMP
```

Multi-turn: Include last N messages in LLM context.

---

### Day 7-8: Evaluation

#### 3.16 Retrieval Quality Metrics

**File**: `worker/src/evaluation.py`

Metrics:
- **Hit Rate**: Does the correct KU appear in top-K results?
- **MRR (Mean Reciprocal Rank)**: At what rank does the correct KU appear?
- **Similarity Distribution**: Histogram of similarity scores

#### 3.17 Evaluation Dataset

Create test queries with expected KU matches:

```json
{
  "evaluations": [
    {
      "query": "I can't log in to my account",
      "expected_ku_ids": [1, 5],
      "expected_topic": "Password Reset"
    }
  ]
}
```

#### 3.18 Evaluation Dashboard

**Route**: `GET /datasets/{dataset}/evaluation`

Display:
- Hit rate @ K (K=1, 3, 5)
- MRR score
- Per-query results with similarity scores
- Failed retrievals (query without matching KU in top-K)

---

## 4. File Change Summary

### New Files

| File | Purpose |
|------|---------|
| `migrations/..._create_knowledge_datasets_table.php` | Dataset table |
| `migrations/..._create_knowledge_dataset_items_table.php` | Dataset items |
| `migrations/..._create_chat_tables.php` | Conversations + messages |
| `app/Models/KnowledgeDataset.php` | Eloquent model |
| `app/Models/KnowledgeDatasetItem.php` | Eloquent model |
| `app/Models/ChatConversation.php` | Eloquent model |
| `app/Models/ChatMessage.php` | Eloquent model |
| `app/Http/Controllers/KnowledgeDatasetController.php` | Dataset CRUD |
| `app/Http/Controllers/Api/RetrievalController.php` | /api/retrieve |
| `app/Http/Controllers/Api/ChatController.php` | /api/chat |
| `views/dashboard/datasets/index.blade.php` | Dataset list |
| `views/dashboard/datasets/create.blade.php` | Create form |
| `views/dashboard/datasets/show.blade.php` | Dataset detail |
| `views/dashboard/datasets/evaluation.blade.php` | Eval dashboard |

### Modified Files

| File | Change |
|------|--------|
| `routes/web.php` | Dataset routes |
| `routes/api.php` | /api/retrieve, /api/chat |
| `dashboard/index.blade.php` | Datasets link in nav |

---

## 5. Architecture Decision: Retrieval Path

| Option | Pros | Cons |
|--------|------|------|
| A: Laravel direct | Simple, no new service | PHP Bedrock SDK setup needed |
| B: Python FastAPI | Reuse existing bedrock_client.py | New service to deploy |
| C: SQS sync | Reuse worker | High latency for chat |

**Recommendation: Option A** — Laravel calls Bedrock via AWS SDK for PHP + pgvector via raw SQL. Keeps architecture simple for Phase 4. Can extract to Python service in Phase 5 if needed.

---

## 6. Risks and Mitigations

| Risk | Mitigation |
|------|------------|
| pgvector query latency on large datasets | IVFFlat index, LIMIT clause, similarity threshold |
| Bedrock embedding latency for real-time queries | Embedding cache (97% hit rate), warm-up |
| LLM hallucination in chat responses | "Only use knowledge base" system prompt, source citation |
| Chat API token costs | Use Haiku for chat, Sonnet for complex queries |
| Multi-turn context overflow | Limit to last 5 messages, summarize older history |

---

## 7. Implementation Order Rationale

1. **Dataset tables first** (Day 1): Foundation for all other features
2. **Dataset UI** (Day 1-2): Enable dataset creation from approved KUs
3. **Export** (Day 2): Validate dataset structure before building retrieval
4. **Vector retrieval** (Day 3-4): Core RAG capability
5. **Chatbot API** (Day 5-6): End-to-end user-facing feature
6. **Evaluation** (Day 7-8): Quality assurance before production

---

*Senior Engineer — 2026-03-24*
