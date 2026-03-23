
# CTO Review — Phase 2 Implementation Plan Approval

**Date:** 2026-03-24  
**From:** CTO  
**To:** Senior Engineer  
**Subject:** Phase 2 Implementation Plan Review and Approval

---

## 1. Overall Review

Phase 2 実装計画を確認しました。

**結論：Phase 2 実装計画を承認します。**

Cluster → Knowledge Unit 変換の設計、ステップ分割、LLM 利用方法、
Knowledge Unit テーブル設計、Export 機能まで含まれており、
Phase 2 の目的を十分に満たす計画です。

---

## 2. Phase 2 の位置づけ（重要）

このプロジェクトのフェーズ構造を改めて整理します。

| Phase | 内容 |
|------|------|
| Phase 0 | Pipeline Infrastructure |
| Phase 1 | Embedding + Clustering |
| Phase 2 | Cluster → Knowledge Unit |
| Phase 3 | Knowledge Dataset Export / Versioning |
| Phase 4 | Chatbot Integration |

**Phase 2 はこのプロジェクトの核心フェーズです。**

ここで初めて最終成果物である Knowledge Unit が生成されます。

---

## 3. Knowledge Unit Definition (Final)

Knowledge Unit の構造を最終定義として確定します。

```
Knowledge Unit

- id
- topic
- intent
- summary
- representative_examples[]
- keywords[]
- cluster_id
- cluster_size
- centroid_vector
- language
- confidence
- review_status
- created_at
- updated_at
```

### review_status

| ステータス | 意味 |
|------------|------|
| draft | LLM 自動生成 |
| reviewed | 人間レビュー済み |
| approved | ナレッジとして採用 |
| rejected | 不採用 |

これは将来の Knowledge Review Workflow に必要になります。

---

## 4. Pipeline Structure After Phase 2

Phase 2 完了後のパイプライン：

```
CSV
 → preprocess
 → embedding
 → clustering
 → cluster_analysis
 → knowledge_unit_generation
 → knowledge_units table
 → export
```

この時点でシステムは

**Knowledge Unit Generator**

になります。

---

## 5. Very Important Design Recommendation

Phase 2 で以下の 2 つのテーブル追加を検討してください。

### 5.1 knowledge_unit_versions

Knowledge Unit のバージョン管理。

```
knowledge_unit_versions
  id
  knowledge_unit_id
  topic
  intent
  summary
  representative_examples_json
  keywords_json
  version
  created_at
```

理由：
- LLM 再生成
- 人間修正
- 変更履歴
- ナレッジ更新
- A/B テスト

Knowledge は必ず更新されるため、
**Knowledge Versioning は重要機能**です。

---

### 5.2 cluster_analysis_logs

LLM の入出力ログ保存。

```
cluster_analysis_logs
  id
  cluster_id
  prompt
  response_json
  model
  prompt_version
  created_at
```

理由：
- LLM 出力の再現性
- プロンプト改善
- 品質分析
- コスト分析
- 監査ログ

LLM を使うシステムでは
**Prompt / Response Log は必須**です。

---

## 6. Prompt Versioning

以下を実装してください：

```
PROMPT_VERSION = "cluster_analysis_v1"
```

knowledge_units または cluster_analysis_logs に
prompt_version を保存してください。

これは将来非常に重要になります。

---

## 7. Phase 2 Completion Criteria (Confirmed)

Phase 2 完了条件を最終確定します。

| # | 条件 |
|---|------|
| 1 | cluster_analysis ステップが動作 |
| 2 | topic_name が生成される |
| 3 | intent が生成される |
| 4 | summary が生成される |
| 5 | knowledge_units テーブルに保存 |
| 6 | Knowledge Unit Dashboard 表示 |
| 7 | Knowledge Unit Export JSON |
| 8 | Knowledge Unit Export CSV |

---

## 8. Project Status Overview

Phase 2 完了時点のシステム：

| 機能 | 状態 |
|------|------|
| CSV Upload | 完了 |
| Embedding | 完了 |
| Clustering | 完了 |
| Cluster Visualization | 完了 |
| Topic Naming | Phase 2 |
| Intent Detection | Phase 2 |
| Summary Generation | Phase 2 |
| Knowledge Unit | Phase 2 |
| Export | Phase 2 |
| Knowledge Review | Phase 3 |
| Chatbot Integration | Phase 4 |

---

## 9. Final CTO Decision

| 項目 | 決定 |
|------|------|
| Phase 2 Implementation Plan | 承認 |
| LLM | Claude Sonnet |
| Structured Output | 必須 |
| Prompt Versioning | 実装 |
| KU Versioning | Phase 2 または Phase 3 |
| LLM Logs | 保存 |
| Export | JSON + CSV |

---

## 10. Instruction

**Phase 2 Implementation を開始してください。**

