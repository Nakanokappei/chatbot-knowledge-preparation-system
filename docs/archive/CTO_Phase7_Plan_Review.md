# CTO Review — Phase 7 Production Launch Plan

**Date:** 2026-03-24  
**From:** CTO  
**To:** Senior Engineer  
**Subject:** Phase 7 Production Launch Plan Review

---

## 1. Overall Assessment

Phase 7 Production Launch Plan を確認しました。

**結論：計画は妥当であり、Production Launch 計画として承認します。**  
構成、順序、RLS 対応、Secrets Manager、CI/CD、Rollback Plan まで含まれており、実運用開始前の計画として十分です。

特に以下を評価します。

- staging → verification → production の順序
- non-owner DB user による RLS 強制
- Secrets Manager を前提にした資格情報管理
- rollback plan を明示していること
- post-launch monitoring を最初の 48 時間に集中させていること
- staging で Phase 6 テストを再実施する方針

この順番で進めるのが正しいです。

---

## 2. Critical Review Points

## 2.1 RDS Instance Class
現行計画では production RDS が `db.t3.medium` になっていますが、**本番は t 系を避けてください。**

### CTO Decision
Production RDS は以下のいずれかに変更してください。

- 第一候補: `db.r6g.large`
- 最小許容: `db.m6g.large`

### 理由
- t 系は burst 前提で、継続負荷に向かない
- pgvector / RLS / API / retrieval が乗るため安定性が重要
- 本システムは chat + retrieval + metadata の複合負荷がある

**staging は小さくてよいですが、production は r/m 系にしてください。**

---

## 2.2 PostgreSQL Version
計画では PostgreSQL 15 ですが、既存開発環境レポートでは PostgreSQL 17 系が出ていました。  
**本番は開発環境と揃える**ことを優先してください。

### CTO Decision
- 既存 migration / pgvector / app code が PostgreSQL 17 で検証済みなら、本番も 17 に統一
- 15 に落とす場合は staging で完全互換確認が必要

---

## 2.3 Secrets Handling
Step 3 は方向として正しいです。  
ただし `.env` に平文で落とすのではなく、**ECS task definition で Secrets Manager 参照**にしてください。

### CTO Rule
- app container: DB / APP_KEY / AWS secrets を task definition secrets で注入
- worker container: DB / SQS / Bedrock secrets を同様に注入
- GitHub Actions には長期鍵を置かない
- OIDC または短期認証を優先

---

## 2.4 ECS Topology
Production では少なくとも以下を分けてください。

### Services
- `ckps-app` service
- `ckps-worker` service

### 推奨
- app: desired count 2 以上
- worker: desired count 1 以上
- ALB は app のみ
- worker は internal / queue consumer

---

## 2.5 Domain + HTTPS
方向は正しいです。  
加えて以下を必須にします。

### 必須事項
- HTTP → HTTPS redirect
- HSTS
- ALB access logs 有効
- ACM certificate 自動更新前提
- health check endpoint `/up` または `/health`

---

## 3. Production Readiness Gates

Production deploy の前に、以下を **Gate** として明示してください。

| Gate | 条件 |
|---|---|
| Gate 1 | staging deploy 成功 |
| Gate 2 | Phase 6 の infra-dependent tests 成功 |
| Gate 3 | load test SLO 達成 |
| Gate 4 | restore drill 成功 |
| Gate 5 | RLS enforced with non-owner DB user |
| Gate 6 | CloudWatch alarms / dashboards 可視化 |
| Gate 7 | budget enforcement 動作確認 |
| Gate 8 | rollback 手順リハーサル |

**すべて通過してから production deploy** としてください。

---

## 4. Launch Strategy

Production launch は **single cutover** ではなく、段階導入にしてください。

### CTO Decision — Launch Strategy
1. staging deploy
2. infra tests
3. production deploy
4. internal-only verification
5. limited tenant rollout
6. full rollout

### limited rollout
- 1 tenant
- 1 dataset
- retrieval + chat のみ
- 24h 監視

この段階を挟むことで事故を減らせます。

---

## 5. Post-Launch Monitoring

Day 7+ の記述は良いです。  
ただし最初の 48 時間は以下を監視対象に固定してください。

| Metric | Threshold |
|---|---|
| Retrieval p95 | < 800ms |
| Retrieval p99 | < 2s |
| Chat p95 | < 5s |
| Chat p99 | < 12s |
| Error rate | < 1% |
| DLQ messages | 0 |
| Token cost spike | 異常増加なし |
| 429 rate limit responses | 想定内 |
| Budget blocks | tenant 単位で妥当 |

---

## 6. Additional Required Items

以下を計画に追加してください。

### 6.1 Database Migration Plan
- migration 実行順
- migration rollback 手順
- migration 前バックアップ
- pgvector extension 有効確認

### 6.2 Smoke Test Script
本番 deploy 直後に自動で回す smoke test を定義してください。

最低限：
- `/up`
- login
- dataset list
- `/api/retrieve`
- `/api/chat`
- CloudWatch metric emission

### 6.3 Runbook
最低限の runbook を用意してください。

- Bedrock errors
- SQS backlog
- DLQ messages
- RDS connection saturation
- high latency
- rollback

---

## 7. Phase 7 Completion Criteria

Phase 7 完了条件を以下で確定します。

1. staging ECS cluster 稼働
2. production ECS cluster 稼働
3. production RDS 稼働（non-owner app user）
4. Secrets Manager 注入構成完了
5. app / worker の ECS deploy 成功
6. Phase 6 infra-dependent tests 成功
7. load test SLO 達成
8. restore drill 成功
9. CloudWatch alarms / dashboard 稼働
10. domain + HTTPS 稼働
11. limited rollout 成功
12. rollback rehearsal 成功

---

## 8. Final CTO Decision

| Item | Decision |
|------|----------|
| Phase 7 Plan | Approved |
| RDS class | Change to r6g.large or m6g.large |
| Secrets | ECS secrets injection |
| Launch strategy | staged rollout |
| Production gate | 8 gates required |
| Completion | limited rollout success required |

---

## 9. Instruction

**上記修正を反映のうえ、Phase 7 Production Launch を進めてください。**
