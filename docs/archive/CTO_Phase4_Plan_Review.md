# CTO Review — Phase 4 Implementation Plan

**Date:** 2026-03-24  
**From:** CTO  
**To:** Senior Engineer  
**Subject:** Phase 4 Implementation Plan Review

---

## 1. Overall Assessment

Phase 4 実装計画を確認しました。

**結論：計画は概ね妥当であり、着手を承認します。**  
ただし、Phase 4 はこれまでのフェーズと違い、**検索品質・応答品質・運用責任**が直接ユーザー体験に出る段階です。  
そのため、以下の修正方針を反映した上で進めてください。

---

## 2. 評価が高い点

以下は良い判断です。

- `knowledge_datasets` / `knowledge_dataset_items` を明示的に分離していること
- published dataset を immutable とする設計
- approved Knowledge Units のみを Dataset 化する方針
- pgvector を使った Retrieval を先に作り、その後 Chat API に進む順序
- Evaluation を Phase 4 に含めていること
- Chatbot を最初から巨大機能にせず、RAG の最小経路で実装する方針
- Retrieval path を Laravel direct で始める提案

この順番は正しいです。  
特に、**Dataset → Retrieval → Chat → Evaluation** の順序は妥当です。

---

## 3. CTO判断（重要）

## 3.1 Retrieval Path
**Option A: Laravel direct** を採用します。

理由：
- Phase 4 の目的は Retrieval / Chatbot の価値検証であり、サービス分割ではない
- 新しい常駐 Python API を増やすより、まずは単純な構成で品質確認すべき
- Phase 5 で必要になれば Retrieval Service を独立させればよい

したがって、Phase 4 は以下で進めてください。

- Laravel が Bedrock Embedding を呼ぶ
- Laravel が pgvector 検索を実行する
- Laravel が Bedrock LLM を呼ぶ
- Python Worker は非同期バッチ用途に残す

---

## 3.2 Knowledge Dataset
Knowledge Dataset の定義を以下で確定します。

### Knowledge Dataset
- approved Knowledge Units の集合
- chatbot に投入される単位
- version を持つ
- published dataset は immutable
- retrieval は published dataset に対してのみ行う

### 状態
| status | meaning |
|---|---|
| draft | 編集中 |
| published | チャットボット利用中 |
| archived | 旧版 |

この状態管理で進めてください。

---

## 3.3 Chat API の責務
Chat API は **最小限の RAG 応答** に留めてください。

Phase 4 の Chat API の責務は以下です。

1. ユーザー入力を受け取る
2. dataset 内で Retrieval する
3. 上位 K 件の Knowledge Unit を文脈化する
4. LLM に回答生成させる
5. 使用した Knowledge Unit を返す

つまり、Phase 4 の chat は

**Conversational UI ではなく、RAG 検証 API**

です。

会話機能は最小に留めてください。

---

## 4. 修正指示

## 4.1 Dataset Export JSON から embedding 配列を外す
現行案では export JSON に `embedding` の 1024 次元配列を含めていますが、Phase 4 では外してください。

理由：
- JSON が肥大化する
- Chatbot 側で人間が読む用途に不要
- Retrieval は DB 側ベクトル検索で行うため、export に含める必要がない
- 配布データと内部索引データは分けるべき

### 方針
- Chatbot 用 export JSON は **テキスト中心**
- embedding は RDS / pgvector 側で保持
- 将来必要なら別形式で vector export を定義

---

## 4.2 Retrieval API のレスポンスを整理
Retrieval API は以下のレスポンスにしてください。

```json
{
  "query": "How do I reset my password?",
  "dataset_id": 1,
  "results": [
    {
      "knowledge_unit_id": 1,
      "topic": "Password Reset",
      "intent": "Account Access Recovery",
      "summary": "...",
      "resolution_summary": "...",
      "similarity": 0.92,
      "confidence": 0.95
    }
  ],
  "latency_ms": 150
}
```

`cause_summary` は返してもよいですが、まずは
- topic
- intent
- summary
- resolution_summary
- similarity
を優先してください。

---

## 4.3 Evaluation を Retrieval 中心にする
Phase 4 の評価対象は、まず **Chat quality ではなく Retrieval quality** にしてください。

優先順位：

1. Hit Rate@K
2. MRR
3. Similarity distribution
4. Query latency
5. 失敗クエリ分析

Chat の自然さ評価は Phase 4 後半または Phase 5 で十分です。

---

## 4.4 Dataset 作成ルール
Dataset 作成時のルールを固定してください。

- 追加可能なのは `approved` のみ
- `draft` / `reviewed` / `rejected` は追加不可
- published dataset は編集不可
- new version は published dataset を clone して draft 作成
- dataset item には `included_version` を保存

このルールは妥当で、そのまま採用します。

---

## 4.5 Chat Conversation は最小実装
`chat_conversations` / `chat_messages` の追加は問題ありません。  
ただし、Phase 4 では以下に限定してください。

- conversation_id
- tenant_id
- dataset_id
- user / assistant message
- source knowledge units
- tokens_used
- created_at

評価対象はあくまで Retrieval / RAG です。  
会話要約、長期記憶、高度な multi-turn 最適化はまだ不要です。

---

## 5. Phase 4 完了基準（最終確定）

Phase 4 の完了基準を以下で確定します。

1. `knowledge_datasets` テーブルが存在
2. `knowledge_dataset_items` テーブルが存在
3. Dataset Versioning が機能する
4. Published Dataset を JSON で export できる
5. Vector Retrieval が動作する
6. `/api/retrieve` が動作する
7. `/api/chat` が動作する
8. Retrieval Quality Evaluation が実行できる

補足：
- Chat API は最小 RAG 検証 API でよい
- export JSON には embedding を含めない

---

## 6. Phase 4 実装順序

以下の順序で進めてください。

1. knowledge_datasets / knowledge_dataset_items
2. Dataset UI
3. Published-only export JSON
4. Retrieval query + pgvector index
5. `/api/retrieve`
6. `/api/chat`
7. Evaluation dashboard

この順番で問題ありません。

---

## 7. Project Status

Phase 4 に入る時点で、プロジェクトは以下の状態です。

| Phase | 内容 | 状態 |
|------|------|------|
| Phase 0 | Infrastructure | 完了 |
| Phase 1 | Embedding + Clustering | 完了 |
| Phase 2 | Knowledge Unit Generation | 完了 |
| Phase 3 | Knowledge Review / Management | 完了 |
| Phase 4 | Dataset + Retrieval + Chatbot | 今回 |
| Phase 5 | Production / Scaling / Monitoring | 以後 |

ここまでで **Knowledge Preparation Platform** は完成しています。  
Phase 4 から **Knowledge Consumption Platform** に入ります。

---

## 8. Final CTO Decision

| 項目 | 判断 |
|------|------|
| Phase 4 Plan | 承認 |
| Retrieval Path | Laravel direct |
| Dataset Status | draft / published / archived |
| Dataset Source | approved KU only |
| Export JSON | embedding なし |
| Chat API | 最小 RAG API |
| Evaluation | Retrieval quality 中心 |

---

## 9. Instruction

**上記修正方針を反映のうえ、Phase 4 Implementation を開始してください。**

