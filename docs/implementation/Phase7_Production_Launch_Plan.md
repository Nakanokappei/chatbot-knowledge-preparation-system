# Phase 7 Production Launch Plan

**Date**: 2026-03-24
**Approval**: CTO_Phase6_Completion_Review_Phase7_Launch.md
**Goal**: Deploy the system to production on AWS with full verification

---

## 1. Production Launch Checklist (CTO Defined)

| # | Item | Category |
|---|------|----------|
| 1 | ECS Cluster (production) | Infrastructure |
| 2 | ECS Cluster (staging) | Infrastructure |
| 3 | RDS Production Instance | Infrastructure |
| 4 | Secrets Manager | Security |
| 5 | IAM Roles Applied | Security |
| 6 | FORCE RLS + Non-owner DB User | Security |
| 7 | ECR Repositories | Infrastructure |
| 8 | CI/CD Pipeline Tested | Automation |
| 9 | CloudWatch Alarms | Monitoring |
| 10 | Load Test | Performance |
| 11 | Restore Drill | Operations |
| 12 | Domain + HTTPS | Infrastructure |
| 13 | Rate Limiting | Security |
| 14 | Budget Enforcement | Operations |
| 15 | Monitoring Dashboard | Monitoring |

---

## 2. Implementation Steps

### Step 1: AWS Infrastructure (Day 1-2)

```bash
# ECR repositories
aws ecr create-repository --repository-name ckps-app --region ap-northeast-1
aws ecr create-repository --repository-name ckps-worker --region ap-northeast-1

# ECS clusters
aws ecs create-cluster --cluster-name ckps-staging --region ap-northeast-1
aws ecs create-cluster --cluster-name ckps-production --region ap-northeast-1

# RDS (production)
# Use Multi-AZ, automated backups, non-public, encrypted
aws rds create-db-instance \
  --db-instance-identifier ckps-production \
  --db-instance-class db.t3.medium \
  --engine postgres \
  --engine-version 15 \
  --master-username ckps_admin \
  --allocated-storage 50 \
  --backup-retention-period 7 \
  --multi-az \
  --storage-encrypted \
  --no-publicly-accessible \
  --region ap-northeast-1
```

### Step 2: Non-owner DB User for RLS (Day 2)

**Critical**: table owner always bypasses RLS in PostgreSQL.

```sql
-- Create application user (non-superuser, non-owner)
CREATE ROLE ckps_app LOGIN PASSWORD 'from_secrets_manager';

-- Grant necessary permissions
GRANT CONNECT ON DATABASE ckps TO ckps_app;
GRANT USAGE ON SCHEMA public TO ckps_app;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO ckps_app;
GRANT USAGE ON ALL SEQUENCES IN SCHEMA public TO ckps_app;

-- RLS will now be enforced because ckps_app is not the table owner
```

Update `.env` for both app and worker:
```
DB_USERNAME=ckps_app
DB_PASSWORD=from_secrets_manager
```

### Step 3: Secrets Manager (Day 2)

```bash
# Store secrets
aws secretsmanager create-secret --name ckps/production/db \
  --secret-string '{"username":"ckps_app","password":"..."}'
aws secretsmanager create-secret --name ckps/production/app-key \
  --secret-string '{"key":"base64:..."}'
```

### Step 4: IAM Roles (Day 2)

Create ECS task execution roles with least-privilege policies per Security_Checklist.md.

### Step 5: Build + Deploy to Staging (Day 3)

```bash
# Build and push images
docker build -t ckps-app ./app
docker build -t ckps-worker ./worker
docker tag ckps-app:latest 444332185803.dkr.ecr.ap-northeast-1.amazonaws.com/ckps-app:latest
docker tag ckps-worker:latest 444332185803.dkr.ecr.ap-northeast-1.amazonaws.com/ckps-worker:latest
docker push ...

# Deploy to staging
./scripts/deploy.sh staging latest
```

### Step 6: Staging Verification (Day 3-4)

Run all Phase 6 tests against staging:
- E2E pipeline
- Multi-tenant isolation (with non-owner DB user — RLS fully effective)
- Load test against SLO targets
- Cost tracking
- Monitoring alarms

### Step 7: Domain + HTTPS (Day 4)

- ALB with ACM certificate
- Route 53 DNS records
- HTTPS redirect

### Step 8: CloudWatch Setup (Day 4)

```bash
# Deploy alarms
# Use infra/cloudwatch-alarms.json as template
# Create SNS topic for alerts
aws sns create-topic --name ckps-alerts --region ap-northeast-1
```

### Step 9: Load Test (Day 5)

```bash
python3 tests/load/retrieval_load_test.py \
  --base-url https://staging.ckps.example.com \
  --token $TOKEN --dataset-id 1 --duration 300
```

### Step 10: Restore Drill (Day 5)

Follow `docs/deployment/Backup_Recovery_Guide.md`.

### Step 11: CI/CD Pipeline Test (Day 5)

Push to main → verify full pipeline → staging → production.

### Step 12: Production Deploy (Day 6)

```bash
./scripts/deploy.sh production $(git rev-parse HEAD)
```

### Step 13: Production Verification (Day 6)

- Health check
- E2E smoke test
- Monitoring dashboard live
- Rate limiting active
- Budget enforcement active

---

## 3. RLS Verification Note

| Environment | DB User | RLS Enforced |
|------------|---------|-------------|
| Local dev | nakanokappei (owner) | No (by PostgreSQL design) |
| Staging | ckps_app (non-owner) | **Yes** |
| Production | ckps_app (non-owner) | **Yes** |

This is standard PostgreSQL behavior and not a bug.

---

## 4. Rollback Plan

If production deploy fails:

1. ECS automatically rolls back to previous task definition
2. RDS: point-in-time recovery available
3. Manual rollback: `./scripts/deploy.sh production <previous-sha>`

---

## 5. Post-Launch Monitoring (Day 7+)

First 48 hours:
- Watch CloudWatch dashboards continuously
- Monitor error rates and latency
- Verify cost tracking accuracy
- Check DLQ for failed messages

---

*Senior Engineer — 2026-03-24*
