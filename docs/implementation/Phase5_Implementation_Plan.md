# Phase 5 Implementation Plan — Production / Scaling / Monitoring

**Date**: 2026-03-24
**Approval**: CTO_Phase4_Completion_Approval_Phase5_Start.md
**Goal**: Production readiness — monitoring, cost control, rate limiting, CI/CD, backup

---

## 1. Phase 5 Completion Criteria (CTO Priority Order)

### High Priority

| # | Criterion | Day |
|---|-----------|-----|
| 1 | CloudWatch Monitoring (metrics + alarms) | Day 1-2 |
| 2 | Cost Tracking (token usage per tenant) | Day 2-3 |
| 3 | Rate Limiting (tenant API throttling) | Day 3 |

### Medium Priority

| # | Criterion | Day |
|---|-----------|-----|
| 4 | Load Testing (retrieval + chat concurrency) | Day 4 |
| 5 | CI/CD Pipeline (GitHub Actions → ECR → ECS) | Day 5-6 |
| 6 | Backup Strategy (RDS snapshot + S3 lifecycle) | Day 6 |

### Low Priority (if time permits)

| # | Criterion | Day |
|---|-----------|-----|
| 7 | HNSW index upgrade | Day 7 |
| 8 | Security review (IAM + secrets rotation) | Day 7-8 |

---

## 2. Implementation Plan

### Day 1-2: CloudWatch Monitoring

#### 2.1 Custom Metrics

**File**: `app/app/Services/MetricsService.php`

Publish custom metrics to CloudWatch via AWS SDK for PHP:

| Metric | Namespace | Dimensions |
|--------|-----------|------------|
| RetrievalLatency | CKPS/Retrieval | tenant_id, dataset_id |
| RetrievalResultCount | CKPS/Retrieval | tenant_id |
| ChatLatency | CKPS/Chat | tenant_id |
| ChatTokensUsed | CKPS/Chat | tenant_id, model_id |
| EmbeddingLatency | CKPS/Embedding | tenant_id |
| PipelineStepDuration | CKPS/Pipeline | step, tenant_id |
| PipelineStepErrors | CKPS/Pipeline | step, error_type |

#### 2.2 CloudWatch Alarms

**File**: `infra/cloudwatch-alarms.json`

| Alarm | Threshold | Action |
|-------|-----------|--------|
| High Retrieval Latency | p99 > 3000ms for 5min | SNS notification |
| High Chat Latency | p99 > 10000ms for 5min | SNS notification |
| Pipeline Step Failures | > 3 in 15min | SNS notification |
| High Token Cost | > $10/hour | SNS notification |
| SQS DLQ Messages | > 0 | SNS notification |
| Worker CPU High | > 80% for 10min | SNS notification |

#### 2.3 Middleware Integration

**File**: `app/app/Http/Middleware/TrackApiMetrics.php`

Automatically capture latency/tokens for `/api/retrieve` and `/api/chat` endpoints.

#### 2.4 Worker Metrics

**File**: `worker/src/metrics.py`

Emit pipeline step metrics from Python worker via boto3 CloudWatch client.

---

### Day 2-3: Cost Tracking

#### 2.5 Token Usage Table

**File**: `app/database/migrations/..._create_token_usage_table.php`

```sql
token_usage
  id              BIGSERIAL PRIMARY KEY
  tenant_id       BIGINT NOT NULL
  user_id         BIGINT
  endpoint        VARCHAR(50)    -- retrieve, chat, cluster_analysis
  model_id        VARCHAR(255)
  input_tokens    INTEGER
  output_tokens   INTEGER
  estimated_cost  DECIMAL(10,6)  -- USD
  created_at      TIMESTAMP

  INDEX (tenant_id, created_at)
  INDEX (tenant_id, endpoint)
```

#### 2.6 Cost Calculation

Model pricing (per 1M tokens, Bedrock ap-northeast-1):

| Model | Input | Output |
|-------|-------|--------|
| Claude 3.5 Haiku | $1.00 | $5.00 |
| Claude 3.5 Sonnet | $3.00 | $15.00 |
| Titan Embed v2 | $0.02 | N/A |

Pricing stored in `llm_models` table (add `input_price_per_1m` and `output_price_per_1m` columns).

#### 2.7 Cost Dashboard

**File**: `app/resources/views/dashboard/cost.blade.php`

Display:
- Monthly cost by tenant
- Cost by endpoint (retrieve vs chat vs pipeline)
- Cost by model
- Daily trend chart
- Top 10 expensive queries

**Route**: `GET /cost`

---

### Day 3: Rate Limiting

#### 2.8 Laravel Rate Limiting

**File**: `app/app/Providers/AppServiceProvider.php` (boot method)

```php
RateLimiter::for('api-retrieve', function (Request $request) {
    return Limit::perMinute(60)->by($request->user()->tenant_id);
});

RateLimiter::for('api-chat', function (Request $request) {
    return Limit::perMinute(20)->by($request->user()->tenant_id);
});
```

#### 2.9 Rate Limit Configuration

Per-tenant rate limits stored in `tenants` table:

| Column | Default |
|--------|---------|
| retrieve_rate_limit | 60/min |
| chat_rate_limit | 20/min |
| monthly_token_budget | 1,000,000 |

#### 2.10 Budget Enforcement

When `monthly_token_budget` is exceeded:
- API returns 429 with `X-Token-Budget-Exceeded` header
- Dashboard shows warning banner
- SNS alert to admin

---

### Day 4: Load Testing

#### 2.11 Load Test Scripts

**File**: `tests/load/retrieval_load_test.py`

Using `locust` or simple async Python:

| Test | Concurrent Users | Duration | Target |
|------|-----------------|----------|--------|
| Retrieval burst | 50 | 2 min | p99 < 3s |
| Chat sustained | 10 | 5 min | p99 < 10s |
| Mixed workload | 30 retrieve + 5 chat | 5 min | No errors |
| Pipeline + API | 2 pipelines + 20 API | 10 min | No interference |

#### 2.12 Results Report

**File**: `docs/reports/Phase5_Load_Test_Report.md`

Record: throughput (req/s), latency percentiles, error rate, CloudWatch correlation.

---

### Day 5-6: CI/CD Pipeline

#### 2.13 GitHub Actions Workflow

**File**: `.github/workflows/deploy.yml`

```yaml
on:
  push:
    branches: [main]

jobs:
  test:
    # Laravel tests + Python tests
  build-push:
    # Build Docker images → Push to ECR
  deploy:
    # Update ECS service with new task definition
```

#### 2.14 ECR Repositories

| Repository | Image |
|------------|-------|
| ckps-app | Laravel + nginx |
| ckps-worker | Python worker |

#### 2.15 Deploy Script

**File**: `scripts/deploy.sh`

Steps:
1. Build Docker images with git SHA tag
2. Push to ECR
3. Register new ECS task definition
4. Update ECS service
5. Wait for deployment stability
6. Rollback on failure

---

### Day 6: Backup Strategy

#### 2.16 RDS Automated Backups

- Enable automated backups (7-day retention)
- Daily snapshot at 03:00 UTC (12:00 JST)
- Point-in-time recovery enabled

#### 2.17 S3 Lifecycle

**File**: `infra/s3-lifecycle.json`

| Path | Rule |
|------|------|
| `{tenant}/jobs/*/embedding/` | IA after 30 days, Glacier after 90 |
| `{tenant}/jobs/*/preprocess/` | Delete after 90 days |
| `exports/` | IA after 30 days |

#### 2.18 Backup Documentation

**File**: `docs/deployment/Backup_Recovery_Guide.md`

---

### Day 7: HNSW Index (Low Priority)

#### 2.19 Index Upgrade Migration

```sql
DROP INDEX IF EXISTS idx_knowledge_units_embedding_cosine;

CREATE INDEX idx_knowledge_units_embedding_hnsw
ON knowledge_units
USING hnsw (embedding vector_cosine_ops)
WITH (m = 16, ef_construction = 64);
```

HNSW is better for >10K vectors. Only apply if dataset grows beyond IVFFlat sweet spot.

---

### Day 7-8: Security Review

#### 2.20 IAM Least Privilege

Review and tighten IAM policies:
- Worker: only SQS receive/delete, S3 get/put, Bedrock invoke, RDS connect
- App: only SQS send, S3 get, Bedrock invoke, RDS connect, CloudWatch put

#### 2.21 Secrets Rotation

- RDS password: rotate via Secrets Manager (90-day schedule)
- API keys: rotate and re-deploy
- Laravel APP_KEY: document rotation procedure

#### 2.22 Security Checklist

**File**: `docs/deployment/Security_Checklist.md`

---

## 3. File Change Summary

### New Files

| File | Purpose |
|------|---------|
| `app/Services/MetricsService.php` | CloudWatch custom metrics |
| `app/Http/Middleware/TrackApiMetrics.php` | API latency/token tracking |
| `worker/src/metrics.py` | Pipeline step metrics |
| `migrations/..._create_token_usage_table.php` | Cost tracking |
| `migrations/..._add_pricing_to_llm_models.php` | Model pricing columns |
| `migrations/..._add_rate_limits_to_tenants.php` | Per-tenant limits |
| `views/dashboard/cost.blade.php` | Cost dashboard |
| `infra/cloudwatch-alarms.json` | Alarm definitions |
| `infra/s3-lifecycle.json` | S3 lifecycle rules |
| `.github/workflows/deploy.yml` | CI/CD pipeline |
| `scripts/deploy.sh` | Deploy script |
| `tests/load/retrieval_load_test.py` | Load test |
| `docs/deployment/Backup_Recovery_Guide.md` | Backup procedures |
| `docs/deployment/Security_Checklist.md` | Security review |

### Modified Files

| File | Change |
|------|--------|
| `routes/api.php` | Rate limiter middleware |
| `AppServiceProvider.php` | Rate limiter definitions |
| `RetrievalController.php` | Metrics + cost tracking |
| `ChatController.php` | Metrics + cost tracking |
| `tenants migration` | Rate limit columns |
| `llm_models migration` | Pricing columns |

---

## 4. Risks and Mitigations

| Risk | Mitigation |
|------|------------|
| CloudWatch cost increase | Use 1-minute resolution only for alarms, 5-min for dashboards |
| Rate limiting too aggressive | Start permissive, tighten based on load test results |
| CI/CD breaks production | Blue-green deployment via ECS, automatic rollback |
| Backup restoration untested | Include restore drill in documentation |

---

*Senior Engineer — 2026-03-24*
