# Phase 6 Testing / Verification Plan

**Date**: 2026-03-24
**Approval**: CTO_Phase5_Completion_Review_Phase6_Testing.md
**Goal**: Verify all Phase 0-5 implementations through E2E, load, security, and operational testing

---

## 0. Prerequisites

**Phase 6 のテスト実行には以下が必要です。すべて揃うまでテストは実行できません。**

| Prerequisite | Status | Action |
|-------------|--------|--------|
| Bedrock Anthropic model access | Pending approval | AWS Console で承認確認 |
| DB migrations (Phase 4-5) | Not run | `php artisan migrate` |
| Test user/tenant | Exists (Phase 3) | Verify via tinker |
| Docker Desktop | Installed | `docker compose up` |
| AWS CLI configured | Configured | `~/.aws/credentials` |
| Staging ECS cluster | Not created | AWS CLI / Console |

---

## 1. Phase 6 Completion Criteria (CTO Defined, 9 items)

| # | Criterion | Type | Estimated Time |
|---|-----------|------|----------------|
| 1 | E2E: CSV → KU → Dataset → Retrieval → Chat | Functional | 2-3 hours |
| 2 | Load test SLO achieved | Performance | 1-2 hours |
| 3 | Restore drill successful | Operational | 1 hour |
| 4 | Security checklist completed | Security | 1-2 hours |
| 5 | Staging deploy successful | Infrastructure | 2-3 hours |
| 6 | CI/CD deploy successful | Infrastructure | 1-2 hours |
| 7 | Multi-tenant isolation test | Security | 1 hour |
| 8 | Cost tracking verified | Operational | 30 min |
| 9 | Monitoring alarms verified | Operational | 30 min |

---

## 2. Test Execution Plan

### Test 1: E2E Pipeline (CSV → RAG Chat)

**Goal**: Verify the full pipeline from CSV upload to RAG chat response.

**Steps**:

```
1. Upload CSV (customer_support_tickets.csv, 500 rows)
   POST /dispatch-pipeline

2. Wait for pipeline completion (5 steps):
   preprocess → embedding → clustering → cluster_analysis → knowledge_unit_generation
   Monitor: GET / (dashboard auto-refresh)

3. Review Knowledge Units:
   GET /jobs/{id}/knowledge-units
   Verify: topic, intent, summary generated for each cluster

4. Approve KUs:
   POST /knowledge-units/{id}/review (draft → reviewed → approved)
   Verify: review_status transitions, audit log created

5. Create Knowledge Dataset:
   POST /datasets (select approved KUs)
   Verify: dataset created with correct ku_count

6. Publish Dataset:
   POST /datasets/{id}/publish
   Verify: status = published, immutable

7. Test Retrieval:
   POST /api/retrieve {"query": "password reset", "dataset_id": 1}
   Verify: results returned with similarity scores

8. Test Chat:
   POST /api/chat {"message": "How do I reset my password?", "dataset_id": 1}
   Verify: RAG response with source attribution

9. Export Dataset:
   GET /datasets/{id}/export
   Verify: JSON without embeddings
```

**Pass criteria**: All 9 steps complete without errors.

---

### Test 2: Load Test (SLO Validation)

**Goal**: Verify CTO-defined performance targets.

**SLO Targets**:

| API | p95 | p99 | Error Rate |
|-----|-----|-----|------------|
| Retrieval | < 800ms | < 2s | < 1% |
| Chat | < 5s | < 12s | < 1% |

**Execution**:

```bash
cd tests/load
pip install aiohttp
python3 retrieval_load_test.py \
  --base-url http://localhost:8000 \
  --token $SANCTUM_TOKEN \
  --dataset-id 1 \
  --duration 120
```

**Pass criteria**: All SLO targets met. Results documented in Load Test Report.

---

### Test 3: Restore Drill

**Goal**: Verify backup/restore procedures work.

**Steps**:

```
1. Create RDS snapshot
2. Restore to test instance
3. Verify row counts match
4. Run sample retrieval query against restored DB
5. Verify RLS policies intact
6. Delete test instance
7. Document results in Backup_Recovery_Guide.md
```

**Pass criteria**: Restored DB functional with data integrity.

---

### Test 4: Security Audit

**Goal**: Complete Security_Checklist.md

**Steps**:

```
1. Verify IAM policies (worker: receive-only SQS, app: send-only SQS)
2. Verify no hardcoded secrets in code (grep for passwords, keys, tokens)
3. Verify .env excluded from Docker images
4. Verify RDS not publicly accessible
5. Verify S3 bucket not public
6. Verify CSRF protection on web routes
7. Verify Sanctum auth on API routes
8. Verify RLS active on all tenant tables
```

**Pass criteria**: All Security_Checklist.md items checked.

---

### Test 5: Staging Deploy

**Goal**: Deploy to staging ECS cluster.

**Steps**:

```
1. Create ECR repositories (ckps-app, ckps-worker)
2. Build and push Docker images
3. Create staging ECS cluster
4. Create Secrets Manager entries
5. Run deploy script: ./scripts/deploy.sh staging latest
6. Verify health check: curl https://staging.ckps.example.com/up
7. Run E2E test against staging
```

**Pass criteria**: Staging environment functional.

---

### Test 6: CI/CD Deploy

**Goal**: Verify GitHub Actions pipeline end-to-end.

**Steps**:

```
1. Push a minor change to main
2. Verify GitHub Actions triggers
3. Verify test job passes
4. Verify Docker build + ECR push
5. Verify staging deploy
6. Approve production deploy
7. Verify production deploy
```

**Pass criteria**: Full pipeline green.

---

### Test 7: Multi-tenant Isolation

**Goal**: Verify tenant A cannot access tenant B's data.

**Steps**:

```
1. Create tenant B with separate user
2. Login as tenant B
3. Verify: GET / shows zero jobs (tenant B has no data)
4. Verify: /api/retrieve with tenant A's dataset_id returns 404
5. Verify: Direct SQL query without app.tenant_id returns no rows (RLS)
6. Verify: Knowledge Units, Datasets, Chat all scoped
```

**Pass criteria**: Complete data isolation between tenants.

---

### Test 8: Cost Tracking

**Goal**: Verify token usage recording and budget enforcement.

**Steps**:

```
1. Make 5 /api/retrieve calls
2. Make 3 /api/chat calls
3. Verify token_usage table has 8 records
4. Verify daily_cost_summary updated
5. Verify Cost Dashboard shows correct data
6. Set monthly_token_budget to 100 (artificially low)
7. Verify 80% warning header appears
8. Verify 100% blocks chat API (429)
9. Reset budget to normal
```

**Pass criteria**: Cost tracking accurate, budget enforcement tiers working.

---

### Test 9: Monitoring Alarms

**Goal**: Verify CloudWatch metrics and alarms.

**Steps**:

```
1. Make API calls to generate metrics
2. Verify custom metrics appear in CloudWatch console
3. Verify CKPS namespace exists with expected metrics
4. Trigger an error condition
5. Verify alarm transitions to ALARM state
6. Verify SNS notification received (if configured)
```

**Pass criteria**: Metrics visible, alarms functional.

---

## 3. Test Execution Order

Recommended sequence (dependencies):

```
Prerequisites check
  ↓
Test 1: E2E Pipeline (validates core functionality)
  ↓
Test 7: Multi-tenant Isolation (validates security)
  ↓
Test 8: Cost Tracking (validates operational)
  ↓
Test 9: Monitoring Alarms (validates observability)
  ↓
Test 2: Load Test (validates performance)
  ↓
Test 4: Security Audit (validates hardening)
  ↓
Test 5: Staging Deploy (validates infrastructure)
  ↓
Test 6: CI/CD Deploy (validates automation)
  ↓
Test 3: Restore Drill (validates recovery)
```

---

## 4. Deliverables

| Deliverable | File |
|------------|------|
| E2E Test Results | `docs/reports/Phase6_E2E_Test_Report.md` |
| Load Test Report | `docs/reports/Phase6_Load_Test_Report.md` |
| Security Checklist (signed) | `docs/deployment/Security_Checklist.md` |
| Restore Drill Record | `docs/deployment/Backup_Recovery_Guide.md` |
| Phase 6 Completion Report | `docs/reports/Phase6_Completion_Report.md` |

---

*Senior Engineer — 2026-03-24*
