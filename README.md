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

- **Slot filling**: Extracts primary filter (product/region/department) and question; asks back if missing
- **Fuzzy matching**: LLM-based matching (e.g. "プレステ" → "PlayStation", "LGテレビ" → "LG Smart TV")
- **Two-stage search**: Precise (question-only embedding) → Broad (enriched embedding) fallback
- **LLM filter**: Retrieved KUs filtered by primary filter relevance via LLM, not SQL
- **Input gate**: Rejects off-topic questions, prompt injection, and adversarial inputs
- **Feedback**: Upvote/downvote on responses, KU usage tracking
- **Structured responses**: Cause → Resolution steps → Additional notes in Markdown

## Deployment

### AWS (Terraform)

Full infrastructure-as-code deployment to AWS ECS Fargate. See [AWS_Infrastructure_Setup_Guide.md](AWS_Infrastructure_Setup_Guide.md) for details.

```bash
cd terraform/
terraform init
terraform plan -var-file=envs/dev.tfvars
terraform apply -var-file=envs/dev.tfvars
```

**CI/CD**: Push to `main` triggers GitHub Actions → Docker build → ECR push → ECS deploy automatically.

### Local Development

```bash
# Clone and start
git clone https://github.com/Nakanokappei/chatbot-knowledge-preparation-system.git
cd chatbot-knowledge-preparation-system
cp app/.env.example app/.env
# Edit app/.env: DB_HOST=db, AWS credentials, SQS_QUEUE_URL
docker compose up -d
docker compose exec app php artisan migrate
docker compose exec app php artisan key:generate
open http://localhost:8000
```

| Service | Port |
|---------|------|
| Web App | 8000 |
| PostgreSQL | 5433 |

## Usage

1. **Register/Login** — first user becomes workspace owner
2. **Add LLM models** in Settings → select from AWS Bedrock
3. **Upload CSV** → Configure columns, descriptions, clustering method
4. **Run pipeline** → Monitor progress in sidebar (auto-refreshes)
5. **Review KUs** → Approve/reject generated knowledge units
6. **Chat** → Click "Data & Chat" to interact with approved KUs
7. **Invite members** → Workspace Settings → share invitation link
8. **Export** → Download clusters as CSV or JSON

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
│   ├── bootstrap/              # app.php (middleware, trusted proxies)
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
├── terraform/              # AWS infrastructure (Terraform modules)
├── infra/                  # Database initialization SQL
├── .github/workflows/      # CI/CD (GitHub Actions)
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
| `S3_BUCKET` | S3 bucket for CSV uploads |
| `DB_HOST` / `DB_PORT` / `DB_DATABASE` | PostgreSQL connection |
| `FILESYSTEM_DISK` | `s3` (AWS) or `local` (Docker) |

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
