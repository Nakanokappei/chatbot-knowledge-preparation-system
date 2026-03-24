# Backup & Recovery Guide

**Date**: 2026-03-24

---

## 1. Backup Resources

| Resource | Method | Retention | Schedule |
|----------|--------|-----------|----------|
| RDS PostgreSQL | Automated backup + weekly snapshot | 7 days (auto), 30 days (manual) | Daily 03:00 UTC |
| S3 | Versioning + lifecycle rules | See lifecycle policy | Continuous |
| ECR Images | Image retention policy | Last 30 images per repo | On push |
| Secrets Manager | Version history | Automatic | On rotation |
| CloudWatch Logs | Retention policy | 90 days | Continuous |

---

## 2. RDS Backup Configuration

```bash
# Enable automated backups (7-day retention, 03:00 UTC window)
aws rds modify-db-instance \
  --db-instance-identifier ckps-production \
  --backup-retention-period 7 \
  --preferred-backup-window "03:00-04:00" \
  --region ap-northeast-1

# Create manual snapshot (weekly via EventBridge)
aws rds create-db-snapshot \
  --db-instance-identifier ckps-production \
  --db-snapshot-identifier "ckps-weekly-$(date +%Y%m%d)" \
  --region ap-northeast-1
```

---

## 3. S3 Lifecycle Policy

Apply `infra/s3-lifecycle.json`:

```bash
aws s3api put-bucket-lifecycle-configuration \
  --bucket knowledge-prep-data-dev \
  --lifecycle-configuration file://infra/s3-lifecycle.json
```

Enable versioning:

```bash
aws s3api put-bucket-versioning \
  --bucket knowledge-prep-data-dev \
  --versioning-configuration Status=Enabled
```

---

## 4. ECR Image Retention

```bash
aws ecr put-lifecycle-policy \
  --repository-name ckps-app \
  --lifecycle-policy-text '{"rules":[{"rulePriority":1,"selection":{"tagStatus":"any","countType":"imageCountMoreThan","countNumber":30},"action":{"type":"expire"}}]}'

aws ecr put-lifecycle-policy \
  --repository-name ckps-worker \
  --lifecycle-policy-text '{"rules":[{"rulePriority":1,"selection":{"tagStatus":"any","countType":"imageCountMoreThan","countNumber":30},"action":{"type":"expire"}}]}'
```

---

## 5. Restore Procedures

### RDS Point-in-Time Recovery

```bash
aws rds restore-db-instance-to-point-in-time \
  --source-db-instance-identifier ckps-production \
  --target-db-instance-identifier ckps-restore-test \
  --restore-time "2026-03-24T12:00:00Z" \
  --region ap-northeast-1
```

### RDS Snapshot Restore

```bash
aws rds restore-db-instance-from-db-snapshot \
  --db-instance-identifier ckps-restore-test \
  --db-snapshot-identifier ckps-weekly-20260324 \
  --region ap-northeast-1
```

### Restore Drill Checklist

- [ ] Restore RDS from latest snapshot to test instance
- [ ] Verify row counts match production
- [ ] Verify pgvector data integrity
- [ ] Run sample retrieval query against restored DB
- [ ] Verify RLS policies are intact
- [ ] Delete test instance after verification
- [ ] Document results and date in this file

**Last restore drill**: _Not yet performed_

---

## 6. Disaster Recovery

| Scenario | RTO | RPO | Action |
|----------|-----|-----|--------|
| RDS failure | 15 min | 5 min | Automated failover (Multi-AZ) |
| S3 data loss | Immediate | 0 | Versioning + cross-region |
| Worker failure | 2 min | 0 | ECS auto-restart |
| App failure | 2 min | 0 | ECS auto-restart |
| Region failure | 2-4 hours | 1 hour | Cross-region snapshot restore |
