
# CTO Review — Phase 3 Implementation Plan

**Date:** 2026-03-24  
**From:** CTO  
**To:** Senior Engineer  
**Subject:** Phase 3 Implementation Plan Review

---

## 1. Overall Evaluation

Phase 3 Implementation Plan を確認しました。

**結論：計画は非常に良いです。設計順序・スコープ・技術選択すべて妥当です。**
この計画通りに進めて問題ありません。

特に以下の点を高く評価します：

- Review UI → Export → Multi-tenant → Fargate の順序
- KnowledgeUnitVersion / KnowledgeUnitReview の設計
- Immutable audit log の採用
- RLS による DB レベルのテナント分離
- Docker → ECS → Fargate への移行計画
- Deployment Guide 作成

これはプロダクションシステムとして正しい進め方です。

---

## 2. Architecture Maturity (Important)

Phase 3 が完了すると、このシステムは以下の構成になります。

| Layer | Component |
|------|-----------|
| UI | Laravel Dashboard |
| Control Plane | Laravel |
| Queue | SQS |
| Worker | Python Worker (Fargate) |
| Storage | S3 |
| Metadata | RDS PostgreSQL |
| Vector | pgvector |
| LLM | Bedrock |
| Knowledge | Knowledge Units |
| Review | Human Review UI |
| Export | Knowledge Dataset |

これは構造的に **LLM Knowledge Engineering Platform** です。

ここまで来ると、一般的な Web アプリではなく、
データ基盤 + ML パイプライン + LLM ワークフロー + ナレッジ管理システムです。

---

## 3. Phase 3 Plan Review by Section

### 3.1 Review UI / Edit UI
これは Phase 3 の中核機能です。問題ありません。

**追加提案：**
Knowledge Unit 編集時に以下も保存してください。

- edited_by_user_id
- edited_at
- edit_comment

Knowledge の編集履歴は将来重要になります。

---

### 3.2 Versioning
knowledge_unit_versions の snapshot_json 方式は正しいです。

**Version Increment Rule を定義してください：**

| 操作 | version |
|------|--------|
| LLM 初回生成 | v1 |
| 人間編集 | v2 |
| 再編集 | v3 |
| 承認 | version 変更なし |
| 差し戻し → 再編集 | v4 |

---

### 3.3 Review Workflow
状態遷移は以下で確定してください。

```
draft → reviewed → approved
draft → reviewed → rejected
rejected → draft
```

approved になった Knowledge Unit は
**基本的に編集不可（新 version 作成）** にしてください。

---

### 3.4 Multi-tenant + RLS
これは非常に良い判断です。

**重要なルール：**

すべてのテーブルに tenant_id：

- datasets
- dataset_rows
- jobs
- clusters
- cluster_memberships
- cluster_centroids
- cluster_representatives
- knowledge_units
- knowledge_unit_versions
- knowledge_unit_reviews
- llm_models
- exports

これは必須です。

---

### 3.5 Fargate
Phase 3 で Fargate 移行は正しいタイミングです。

Worker の設計ルール：

- Worker は stateless
- 入力は S3 / RDS
- 出力は S3 / RDS
- ローカルファイル禁止
- tmp は /tmp のみ
- graceful shutdown 対応
- visibility timeout > max step time

---

## 4. Very Important — Knowledge Dataset Concept

Phase 3 で重要になる概念を定義します。

### Knowledge Unit と Knowledge Dataset は別物

**Knowledge Unit**
- 1 クラスタ = 1 ナレッジ
- topic / intent / summary
- embedding
- representative examples

**Knowledge Dataset**
- approved Knowledge Units の集合
- versioned
- chatbot に投入される
- export JSON
- export embeddings

### 将来必要なテーブル

```
knowledge_datasets
  id
  name
  version
  status
  created_at

knowledge_dataset_items
  dataset_id
  knowledge_unit_id
```

これは Phase 3 後半または Phase 4 で実装してください。

---

## 5. Phase Roadmap (Updated)

| Phase | 内容 |
|------|------|
| Phase 0 | Infrastructure |
| Phase 1 | Embedding + Clustering |
| Phase 2 | Knowledge Unit Generation |
| Phase 3 | Knowledge Review + Dataset Management |
| Phase 4 | Chatbot / RAG Integration |
| Phase 5 | Production / Scaling / Monitoring |

---

## 6. Phase 3 Completion Criteria (Reconfirm)

Phase 3 完了条件：

1. Knowledge Unit Review UI
2. Knowledge Unit Edit
3. Review Workflow
4. Approved Only Export
5. Knowledge Unit Version History
6. Multi-tenant Isolation
7. RLS Enabled
8. Worker on Fargate
9. Deployment Guide
10. Knowledge Dataset Export

---

## 7. Final CTO Decision

| 項目 | 判断 |
|------|------|
| Phase 3 Plan | 承認 |
| Review UI | 実装 |
| Versioning | 実装 |
| Audit Log | 実装 |
| Multi-tenant | 実装 |
| RLS | 実装 |
| Fargate | 実装 |
| Knowledge Dataset | Phase 3 後半 |

---

## 8. Instruction

**Phase 3 Implementation を開始してください。**

