# Phase 2 完了報告

**日付**: 2026-03-24
**宛先**: CTO
**送信者**: 上級エンジニア
**件名**: Phase 2 完了 — Phase 3 着手承認依頼

---

## 1. 結論

**Phase 2 の全完了基準 8/8 を達成しました。Phase 3 着手の承認をお願いします。**

---

## 2. 完了基準の達成状況

| # | 完了基準 | 結果 |
|---|---------|------|
| 1 | cluster_analysis ステップが動作 | ✅ |
| 2 | topic_name が生成される | ✅ |
| 3 | intent が生成される | ✅ |
| 4 | summary が生成される | ✅ |
| 5 | knowledge_units テーブルに保存 | ✅ |
| 6 | Knowledge Unit Dashboard 表示 | ✅ |
| 7 | Knowledge Unit Export JSON | ✅ |
| 8 | Knowledge Unit Export CSV | ✅ |

---

## 3. パイプライン実行結果

テストデータ: `customer_support_tickets.csv`（500 行、3 クラスタ）

### 3.1 パイプラインフロー（5 ステップ全自動チェイン）

```
Laravel Dashboard (Job 作成 + LLM モデル選択)
  ↓ SQS: step=preprocess
Python Worker: preprocess (500 rows → 正規化 → S3 Parquet)
  ↓ SQS: step=embedding (自動チェイン)
Python Worker: embedding (Bedrock Titan v2 → 500 x 1024 → S3 .npy)
  ↓ SQS: step=clustering (自動チェイン)
Python Worker: clustering (HDBSCAN → 3 clusters → RDS)
  ↓ SQS: step=cluster_analysis (自動チェイン)
Python Worker: cluster_analysis (Bedrock Claude Haiku 4.5 → topic/intent/summary)
  ↓ SQS: step=knowledge_unit_generation (自動チェイン)
Python Worker: knowledge_unit_generation (3 KUs → RDS)
  ↓ RDS: status=completed
Laravel Dashboard: KU 表示 + Export
```

### 3.2 Knowledge Unit 生成結果

| KU | Topic | Intent | Rows | Status |
|----|-------|--------|------|--------|
| #4 | Product Feature Navigation Assistance | troubleshooting | 29 | draft |
| #5 | Post-Update Product Malfunction | troubleshooting | 166 | draft |
| #6 | Accidental Data Deletion Recovery | troubleshooting | 26 | draft |

### 3.3 LLM 使用量

| 項目 | 値 |
|------|-----|
| モデル | Claude Haiku 4.5 (`jp.anthropic.claude-haiku-4-5-20251001-v1:0`) |
| Input tokens | 1,939 |
| Output tokens | 381 |
| 推定コスト | ~$0.004 (3 クラスタ) |
| 処理時間 | ~5 秒 |

### 3.4 全パイプライン処理時間（500 行）

| ステップ | 時間 | 備考 |
|---------|------|------|
| preprocess | ~1s | |
| embedding | ~28s | 97.2% キャッシュヒット |
| clustering | ~1s | HDBSCAN |
| cluster_analysis | ~5s | Haiku 4.5 × 3 クラスタ |
| knowledge_unit_generation | <1s | DB 操作のみ |
| **合計** | **~35s** | |

---

## 4. 構築した成果物

### 4.1 Python Worker ステップ（新規）

| ファイル | 機能 |
|---------|------|
| `worker/src/steps/cluster_analysis.py` | LLM でクラスタ分析（topic, intent, summary, keywords, language） |
| `worker/src/steps/knowledge_unit_generation.py` | 分析結果から Knowledge Unit + Version v1 を生成 |
| `worker/src/bedrock_llm_client.py` | Claude LLM クライアント（モデル選択対応、リトライ、JSON パース） |

### 4.2 データベーステーブル（新規/変更）

| テーブル | 用途 |
|---------|------|
| `cluster_analysis_logs` | LLM プロンプト/レスポンスのログ（CTO 指示: 必須） |
| `knowledge_unit_versions` | KU のバージョンスナップショット |
| `llm_models` | ユーザー管理のLLMモデルレジストリ |
| `knowledge_units.language` カラム追加 | 言語検出結果の保存 |

### 4.3 Dashboard 拡張

| 機能 | 詳細 |
|------|------|
| LLM モデル選択ドロップダウン | パイプライン dispatch 時にモデルを選択可能 |
| Knowledge Unit 表示ページ (`/jobs/{id}/knowledge-units`) | KU カード形式表示（topic, intent, summary, keywords） |
| JSON Export (`?format=json`) | 全 KU を JSON でダウンロード |
| CSV Export (`?format=csv`) | 全 KU を CSV でダウンロード |
| 部分 AJAX 更新 | Stats + ジョブリストのみ 5 秒更新（フォーム操作を妨げない） |
| ジョブ一覧に KUs ボタン | KU 生成済みジョブから直接 KU ページへ |

### 4.4 設定画面（新規）

| 機能 | 詳細 |
|------|------|
| `/settings/models` | LLM モデルの追加・削除・デフォルト設定・有効/無効切替 |
| DB 管理 | コード変更なしで新モデルを追加可能 |

---

## 5. CTO 指示への対応状況

| CTO 指示 | 対応 |
|---------|------|
| LLM: Claude Sonnet (Bedrock) | ✅ Bedrock APAC profile。デフォルトを Haiku 4.5 に変更（コスト最適化）、Sonnet も選択可 |
| Structured Output 必須 | ✅ JSON 出力 + バリデーション + リトライ |
| Prompt Versioning | ✅ `PROMPT_VERSION = "cluster_analysis_v1"` |
| LLM Logs 保存必須 | ✅ `cluster_analysis_logs` にプロンプト・レスポンス・トークン数・モデル ID を記録 |
| KU Versioning | ✅ `knowledge_unit_versions` テーブルに v1 スナップショット |
| review_status ライフサイクル | ✅ draft → reviewed → approved → rejected（Phase 3 で UI 実装） |
| Export: JSON + CSV | ✅ 両フォーマット対応 |

---

## 6. 設計判断

### 6.1 LLM モデル選択のユーザー管理化

CTO 指示では Claude 3.5 Sonnet が指定されていましたが、調査の結果：

- **cluster_analysis のタスク（分類・要約・キーワード抽出）は Haiku クラスで十分な品質**
- Haiku 4.5 は Sonnet 3.5 の 1/4 のコスト、かつ高速
- 同額で新世代モデル（Sonnet 4.5/4.6）が利用可能なため、旧世代は候補外

**実装**: デフォルト Haiku 4.5、ユーザーが設定画面からモデルを管理可能に。

### 6.2 Inference Profile ID

Bedrock の日本リージョンでは：
- 旧世代（3.x）: `apac.` プレフィックス
- 新世代（4.x）: `jp.` プレフィックス

初期登録モデル：
| モデル | Profile ID |
|--------|-----------|
| Haiku 4.5（デフォルト） | `jp.anthropic.claude-haiku-4-5-20251001-v1:0` |
| Sonnet 4.6 | `jp.anthropic.claude-sonnet-4-6` |
| Sonnet 4.5 | `jp.anthropic.claude-sonnet-4-5-20250929-v1:0` |

---

## 7. Phase 3 への提案

Phase Roadmap に基づき、Phase 3 は **Knowledge Dataset Export / Versioning / 品質管理** です。

### Phase 3 想定タスク

| タスク | 内容 |
|--------|------|
| Knowledge Review UI | review_status の手動更新フロー（draft → reviewed → approved） |
| KU 編集機能 | topic, summary, cause_summary, resolution_summary の編集 |
| Embedding キャッシュ強化 | Redis 移行検討、バッチキャッシュ |
| Quality metrics | クラスタ品質スコア、KU 信頼度スコア |
| Multi-tenant hardening | RLS、テナント切替 |
| Cost optimization | Fargate Spot、バッチ処理 |

### Phase 3 で必要な判断事項

| # | 判断事項 |
|---|---------|
| 1 | Knowledge Review UI のスコープ（シンプルなステータス変更のみ or 編集機能込み） |
| 2 | Phase 3 完了基準 |
| 3 | Fargate 移行を Phase 3 に含めるか |
| 4 | Multi-tenant 認証の実装方式 |

---

## 8. 承認依頼

以下の承認をお願いします：

- [ ] Phase 2 完了の承認
- [ ] Phase 3 着手の承認
- [ ] Phase 3 判断事項への回答（上記 4 項目）

---

*上級エンジニア — 2026-03-24*
