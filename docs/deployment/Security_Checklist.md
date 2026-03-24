# Security Checklist

**Date**: 2026-03-24

---

## 1. IAM Least Privilege

### Worker IAM Policy

| Service | Actions | Resource |
|---------|---------|----------|
| SQS | ReceiveMessage, DeleteMessage, GetQueueAttributes | ckps-pipeline-* |
| S3 | GetObject, PutObject | knowledge-prep-data-* |
| Bedrock | InvokeModel | * (model-specific preferred) |
| RDS | rds-db:connect | ckps-* |
| CloudWatch | PutMetricData | * |

### App IAM Policy

| Service | Actions | Resource |
|---------|---------|----------|
| SQS | SendMessage | ckps-pipeline-* |
| S3 | GetObject | knowledge-prep-data-* |
| Bedrock | InvokeModel | * (model-specific preferred) |
| RDS | rds-db:connect | ckps-* |
| CloudWatch | PutMetricData | * |

### Verification

- [ ] Worker cannot send SQS messages (only receive/delete)
- [ ] App cannot receive SQS messages (only send)
- [ ] Neither can delete S3 buckets
- [ ] Neither can modify IAM policies
- [ ] Neither can access other AWS accounts

---

## 2. Secrets Management

### Secrets Inventory

| Secret | Location | Rotation |
|--------|----------|----------|
| RDS password | Secrets Manager | 90-day automatic |
| Laravel APP_KEY | Secrets Manager | Manual (document procedure) |
| AWS access keys | IAM (prefer instance roles) | 90-day |
| Sanctum tokens | Database | Per-session |

### Rotation Procedure

1. **RDS Password**: Secrets Manager automatic rotation (Lambda)
2. **APP_KEY**: Generate new key → update Secrets Manager → redeploy
3. **AWS Keys**: Prefer IAM roles for ECS tasks (no static keys)

- [ ] Secrets Manager rotation enabled for RDS
- [ ] No hardcoded credentials in code or config
- [ ] No secrets in git history
- [ ] ECS tasks use IAM roles, not access keys
- [ ] .env files excluded from Docker images

---

## 3. Network Security

- [ ] RDS not publicly accessible
- [ ] RDS security group allows only ECS tasks
- [ ] S3 bucket not public
- [ ] S3 bucket policy restricts to VPC endpoint
- [ ] ECS tasks in private subnet with NAT gateway
- [ ] HTTPS enforced on ALB
- [ ] TLS 1.2+ only

---

## 4. Application Security

- [ ] CSRF protection enabled (Laravel default)
- [ ] SQL injection prevented (Eloquent ORM + parameterized queries)
- [ ] XSS prevention (Blade auto-escaping)
- [ ] Rate limiting on API endpoints
- [ ] Budget enforcement prevents runaway costs
- [ ] RLS enforced on all tenant-scoped tables
- [ ] Auth middleware on all routes
- [ ] Sanctum token authentication on API routes
- [ ] Input validation on all endpoints
- [ ] File upload validation (if applicable)

---

## 5. Monitoring Security

- [ ] CloudWatch alarms for unusual patterns
- [ ] DLQ monitoring for failed messages
- [ ] Login failure tracking
- [ ] API error rate monitoring
- [ ] Cost anomaly detection

---

## 6. Compliance

- [ ] No PII in logs
- [ ] Data retention policies documented
- [ ] Tenant data isolation verified (RLS)
- [ ] Backup encryption enabled (RDS, S3)
- [ ] Data export restricted to authorized users

---

**Review completed by**: _Pending_
**Next review date**: _90 days from completion_
