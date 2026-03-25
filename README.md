# Chatbot Knowledge Preparation System (CKPS)

An AI-powered pipeline that transforms raw CSV data into structured knowledge units for RAG-based chatbots. Upload customer support tickets, product manuals, or any text data — CKPS clusters them, extracts structured knowledge via LLM, and provides a conversational chat interface with product-aware slot filling.

## Architecture

```
┌─────────────┐     ┌──────────┐     ┌──────────────────┐
│  Laravel App │────▶│ AWS SQS  │────▶│  Python Worker   │
│  (PHP 8.4)   │     └──────────┘     │  (Python 3.12)   │
│              │                      │                  │
│  - Web UI    │     ┌──────────┐     │  - Preprocess    │
│  - REST API  │────▶│PostgreSQL│◀────│  - Embedding     │
│  - Auth      │     │+ pgvector│     │  - Clustering    │
│  - Chat RAG  │     └──────────┘     │  - LLM Analysis  │
└─────────────┘                      │  - KU Generation │
                                     └──────────────────┘
```

| Component | Tech | Role |
|-----------|------|------|
| **App** | Laravel 13 / PHP 8.4 | Web UI, API, chat, pipeline orchestration |
| **Worker** | Python 3.12 | Pipeline step execution (SQS-driven) |
| **Database** | PostgreSQL 17 + pgvector | Data storage, vector similarity search |
| **LLM** | AWS Bedrock (Converse API) | Cluster analysis, knowledge extraction, chat |
| **Embedding** | Amazon Titan Embed Text v2 | 1024-dim vectors for similarity search |

## Pipeline

```
CSV Upload → Preprocess → Embedding → Clustering → LLM Analysis → Knowledge Units
```

1. **Preprocess** — Encoding detection (UTF-8/Shift-JIS), column selection, text normalization
2. **Embedding** — Vector generation via Bedrock Titan (with caching)
3. **Clustering** — HDBSCAN, K-Means, Agglomerative, or HNSW+Leiden
4. **Cluster Analysis** — LLM names each cluster, extracts intent/summary/keywords
5. **Knowledge Unit Generation** — Structured fields: question, symptoms, root cause, resolution, product, category

## Chat Features

- **Slot filling**: Extracts product name and question separately; asks back if product is missing
- **Product matching**: LLM-based fuzzy matching (e.g. "プレステ" → "PlayStation")
- **Two-stage search**: Precise (question-only embedding) → Broad (enriched embedding) fallback
- **LLM product filter**: Retrieved KUs filtered by product relevance via LLM, not SQL
- **Structured responses**: Cause → Resolution steps → Additional notes in Markdown

## Quick Start

### Prerequisites

- Docker & Docker Compose
- AWS credentials with Bedrock access (for LLM and embedding models)

### Setup

```bash
# Clone the repository
git clone https://github.com/Nakanokappei/chatbot-knowledge-preparation-system.git
cd chatbot-knowledge-preparation-system

# Create environment files
cp app/.env.example app/.env
# Edit app/.env: set DB_HOST=db, DB_PORT=5432, DB_DATABASE=knowledge_prep,
#   DB_USERNAME=ckps_user, DB_PASSWORD=ckps_local_password,
#   AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY, AWS_DEFAULT_REGION,
#   SQS_QUEUE_URL

# Start all services
docker compose up -d

# Run migrations
docker compose exec app php artisan migrate

# Generate app key
docker compose exec app php artisan key:generate

# Access the app
open http://localhost:8000
```

### Default Ports

| Service | Port |
|---------|------|
| Web App | 8000 |
| PostgreSQL | 5433 |

## Usage

1. **Register/Login** at `http://localhost:8000`
2. **Add LLM models** in Settings → select from AWS Bedrock
3. **Upload CSV** → Configure columns, clustering method, and pipeline settings
4. **Run pipeline** → Monitor progress in sidebar (auto-refreshes)
5. **Review KUs** → Approve/reject generated knowledge units
6. **Chat** → Click "Data & Chat" to interact with approved KUs

## API

All API endpoints require Sanctum authentication (`Authorization: Bearer <token>`).

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/datasets` | GET/POST | List/create datasets |
| `/api/pipeline-jobs` | GET/POST | List/create pipeline jobs |
| `/api/retrieve` | POST | Vector similarity search |
| `/api/chat` | POST | RAG chat with published knowledge datasets |

## Project Structure

```
├── app/                    # Laravel application
│   ├── app/
│   │   ├── Http/Controllers/   # Web & API controllers
│   │   ├── Models/             # Eloquent models
│   │   └── Services/           # BedrockService, RagService, CostTrackingService
│   ├── resources/views/        # Blade templates
│   ├── routes/                 # web.php, api.php
│   ├── database/migrations/    # Schema migrations
│   └── lang/                   # en/ja translations
├── worker/                 # Python pipeline worker
│   └── src/
│       ├── main.py             # SQS poller / CLI entry point
│       ├── step_chain.py       # Pipeline step sequencing
│       ├── bedrock_client.py   # Embedding generation
│       ├── bedrock_llm_client.py # LLM invocation (Converse API)
│       └── steps/              # Pipeline step handlers
├── infra/                  # Database initialization SQL
├── docker-compose.yml      # Local development stack
└── docs/                   # Architecture Decision Records
```

## Configuration

### Environment Variables

| Variable | Description |
|----------|-------------|
| `AWS_ACCESS_KEY_ID` | AWS credentials for Bedrock/SQS/S3 |
| `AWS_SECRET_ACCESS_KEY` | AWS secret key |
| `AWS_DEFAULT_REGION` | AWS region (e.g. `ap-northeast-1`) |
| `SQS_QUEUE_URL` | SQS queue URL for pipeline messages |
| `DB_HOST` / `DB_PORT` / `DB_DATABASE` | PostgreSQL connection |

### Supported Clustering Methods

| Method | Best for |
|--------|----------|
| HDBSCAN | Auto cluster count, noise detection |
| K-Means | Fixed cluster count |
| Agglomerative | Hierarchical, small datasets |
| HNSW + Leiden | Large datasets, graph community detection |

## Localization

UI supports English and Japanese. Translation files: `app/lang/en/ui.php`, `app/lang/ja/ui.php`.

## License

[MIT License](LICENSE)
