# CKPS Operations Runbook

**Date**: 2026-03-24

---

## 1. Bedrock Errors

### Symptoms
- Chat API returns 500
- Pipeline `cluster_analysis` step fails
- CloudWatch: `BedrockLatency` spikes or `ChatErrorRate` increases

### Diagnosis
```bash
# Check worker logs
aws logs tail /ecs/ckps-worker --since 30m --filter-pattern "ERROR"

# Check Bedrock service health
aws bedrock list-foundation-models --region ap-northeast-1 --query "modelSummaries[?modelId=='anthropic.claude-3-5-haiku-20251001-v1:0'].modelLifecycle"
```

### Resolution
1. **ThrottlingException**: Reduce concurrent requests, check Bedrock quotas
2. **ModelNotFound**: Verify model access approval in Bedrock console
3. **ServiceUnavailable**: Wait and retry (Bedrock transient issue)
4. **AccessDeniedException**: Check IAM role has `bedrock:InvokeModel` permission

---

## 2. SQS Backlog

### Symptoms
- Jobs stay in `submitted` status
- SQS queue depth increasing
- Worker not processing messages

### Diagnosis
```bash
# Check queue depth
aws sqs get-queue-attributes \
  --queue-url https://sqs.ap-northeast-1.amazonaws.com/444332185803/ckps-pipeline-dev \
  --attribute-names ApproximateNumberOfMessages

# Check worker is running
aws ecs describe-services --cluster ckps-production --services ckps-worker \
  --query 'services[0].{desired:desiredCount,running:runningCount}'
```

### Resolution
1. **Worker crashed**: ECS will auto-restart. Check logs for root cause
2. **Worker scaled to 0**: Update desired count to 1+
3. **SQS permissions**: Verify worker IAM role has SQS receive/delete
4. **Visibility timeout**: If messages reappear, increase timeout (default: 5 min)

---

## 3. DLQ Messages

### Symptoms
- CloudWatch alarm `CKPS-SQS-DLQ-Messages` fires
- Messages appear in `ckps-dlq-dev` queue

### Diagnosis
```bash
# Read DLQ messages (do not delete)
aws sqs receive-message \
  --queue-url https://sqs.ap-northeast-1.amazonaws.com/444332185803/ckps-dlq-dev \
  --max-number-of-messages 5 \
  --visibility-timeout 0
```

### Resolution
1. Inspect message body for job_id, step, error
2. Check worker logs for that job_id
3. Fix root cause (bad data, missing permissions, Bedrock error)
4. Redrive: move messages back to main queue or manually re-dispatch job
5. **Never delete DLQ messages** without understanding the failure

---

## 4. RDS Connection Saturation

### Symptoms
- Application returns "too many connections" errors
- Latency spike across all endpoints
- CloudWatch RDS `DatabaseConnections` metric high

### Diagnosis
```bash
# Check active connections
psql -c "SELECT count(*), state FROM pg_stat_activity GROUP BY state;"

# Check max connections
psql -c "SHOW max_connections;"
```

### Resolution
1. **Connection leak**: Check app/worker code for unclosed connections
2. **Scale issue**: Increase `max_connections` or use PgBouncer
3. **Idle connections**: Kill idle sessions: `SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE state = 'idle' AND query_start < now() - interval '10 minutes';`
4. **Long-running queries**: Identify and terminate: `SELECT pid, now() - pg_stat_activity.query_start AS duration, query FROM pg_stat_activity WHERE state = 'active' ORDER BY duration DESC LIMIT 5;`

---

## 5. High Latency

### Symptoms
- CloudWatch alarms fire for Retrieval or Chat latency
- Users report slow responses

### Diagnosis
```bash
# Check which component is slow
# CloudWatch metrics: BedrockLatency, PgVectorQueryTime, EmbeddingLatency

# Check RDS performance
psql -c "SELECT calls, mean_exec_time, query FROM pg_stat_statements ORDER BY mean_exec_time DESC LIMIT 10;"
```

### Resolution
1. **Bedrock slow**: Check Bedrock service status, consider model downgrade (Haiku)
2. **pgvector slow**: Check index exists, consider HNSW upgrade, VACUUM ANALYZE
3. **Embedding slow**: Check Titan Embed service status
4. **RDS CPU high**: Check slow queries, add indexes, consider instance upgrade
5. **Network**: Check VPC endpoints, NAT gateway throughput

---

## 6. Rollback Procedure

### Application Rollback
```bash
# Find previous working image tag
aws ecs describe-services --cluster ckps-production --services ckps-app \
  --query 'services[0].deployments[*].taskDefinition'

# Deploy previous version
./scripts/deploy.sh production <previous-git-sha>

# Verify
./scripts/smoke_test.sh https://ckps.example.com $TOKEN
```

### Database Rollback
```bash
# If migration caused issues
cd app && php artisan migrate:rollback --step=1

# If data is corrupted, restore from snapshot
aws rds restore-db-instance-from-db-snapshot \
  --db-instance-identifier ckps-restore \
  --db-snapshot-identifier <snapshot-id>
```

### Emergency: Full Rollback
1. Revert to previous ECS task definitions (both app + worker)
2. Restore RDS from point-in-time if needed
3. Verify smoke test passes
4. Notify stakeholders

---

## 7. Common Operations

### Scale Worker
```bash
aws ecs update-service --cluster ckps-production \
  --service ckps-worker --desired-count 3
```

### Run Migration
```bash
# Always backup first
aws rds create-db-snapshot --db-instance-identifier ckps-production \
  --db-snapshot-identifier "pre-migration-$(date +%Y%m%d%H%M)"

# Run migration via ECS exec or CI/CD
php artisan migrate --force
```

### Check Budget Status
```sql
SELECT t.name, dcs.date, dcs.total_cost, dcs.total_tokens
FROM daily_cost_summary dcs
JOIN tenants t ON t.id = dcs.tenant_id
WHERE dcs.date >= CURRENT_DATE - 30
ORDER BY dcs.date DESC;
```

---

*Senior Engineer — 2026-03-24*
