# Phase 2 実装計画

**ステータス**: CTO 承認待ち
**期間**: 2 週間（10 営業日）
**目標**: Cluster → Knowledge Unit 変換（LLM による分析 + 最終成果物生成）

---

## CTO 定義の確認

### Phase 2 の目的

**Cluster → Knowledge Unit 変換**

```
clusters → representatives → LLM Analysis
  → topic naming → intent classification → summary generation
  → Knowledge Unit → knowledge_units table
```

### Knowledge Unit 構造（CTO 定義）

| フィールド | 説明 | 生成方法 |
|-----------|------|---------|
| topic_name | トピック名 | LLM 生成 |
| intent | ユーザー意図 | LLM 生成 |
| summary | 概要 | LLM 生成 |
| representative_examples[] | 代表的な問い合わせ | cluster_representatives から取得 |
| keywords[] | キーワード | LLM 生成 |
| cluster_id | 元クラスタ | DB リレーション |
| cluster_size | クラスタサイズ | clusters.row_count |
| centroid_vector | 中心ベクトル | cluster_centroids から取得 |
| language | 言語 | LLM 自動検出 |
| created_at | 作成日時 | 自動 |

### 技術決定（CTO 確定）

| 項目 | 決定 |
|------|------|
| LLM | Claude 3.5 Sonnet (Bedrock) |
| 入力 | representative_rows 上位 5-10 件 |
| 出力 | JSON (Structured Output) |
| 言語 | 入力言語自動検出 |
| ステップ | cluster_analysis → knowledge_unit_generation |

---

## 完了基準（7 項目）

1. 各クラスタに topic_name が付与される
2. 各クラスタに intent が付与される
3. 各クラスタに summary が生成される
4. Knowledge Unit JSON が生成される
5. knowledge_units テーブルに保存される
6. Dashboard で Knowledge Unit が閲覧できる
7. Knowledge Unit Export ができる

---

## パイプラインステップ設計

Phase 1 の 3 ステップに 2 ステップを追加します。

```
preprocess → embedding → clustering → cluster_analysis → knowledge_unit_generation
  (Phase 1)                            (Phase 2 新規)      (Phase 2 新規)
```

### cluster_analysis ステップ

各クラスタに対して LLM を呼び出し、topic_name / intent / summary を生成。
結果は clusters テーブルに保存。

### knowledge_unit_generation ステップ

cluster_analysis の結果 + centroids + representatives を統合し、
Knowledge Unit を生成。knowledge_units テーブルに保存。

---

## Day 1-2: Bedrock Claude クライアント

### 実装ファイル
- `worker/src/bedrock_llm_client.py`

### 処理内容

```python
bedrock.invoke_model(
    modelId="anthropic.claude-3-5-sonnet-20241022-v2:0",
    body=json.dumps({
        "anthropic_version": "bedrock-2023-05-31",
        "max_tokens": 2048,
        "messages": [
            {"role": "user", "content": prompt}
        ],
        "temperature": 0.0,
    })
)
```

### 設計方針
- Structured Output: JSON 形式で出力を強制
- リトライ: Exponential backoff (最大 3 回)
- レート制限対応: ThrottlingException ハンドリング
- プロンプトバージョン管理: `PROMPT_VERSION = "v1.0"`

---

## Day 3-5: cluster_analysis ステップ

### 実装ファイル
- `worker/src/steps/cluster_analysis.py`

### 処理フロー

```
1. clusters テーブルからジョブのクラスタ一覧を取得
2. 各クラスタに対して:
   a. cluster_representatives から代表行 5-10 件を取得
   b. LLM プロンプトを構築（代表行 + クラスタサイズ）
   c. Claude Sonnet を呼び出し
   d. JSON レスポンスをパース
   e. clusters テーブルの topic_name, intent, summary を更新
3. 次ステップ (knowledge_unit_generation) を SQS に送信
```

### LLM プロンプト設計

```
You are a customer support analyst. Analyze the following support tickets
that belong to the same cluster and extract:

1. topic_name: A concise name for this group of issues (5 words max)
2. intent: The primary customer intent (e.g., "troubleshooting", "billing inquiry", "refund request")
3. summary: A 2-3 sentence summary of the common issue pattern
4. keywords: 3-5 keywords that characterize this cluster
5. language: The primary language of the tickets (e.g., "en", "ja")

Cluster size: {cluster_size} tickets
Representative tickets:
{representative_texts}

Respond in JSON format:
{
  "topic_name": "...",
  "intent": "...",
  "summary": "...",
  "keywords": ["...", "..."],
  "language": "..."
}

IMPORTANT: Base your analysis ONLY on the provided ticket texts.
Do not hallucinate or invent information not present in the tickets.
Respond in the same language as the input tickets.
```

### 出力バリデーション
- JSON パースチェック
- 必須フィールド存在チェック (topic_name, intent, summary, keywords, language)
- topic_name の長さチェック (5 語以下)
- 失敗時: リトライ（最大 3 回）

---

## Day 6-8: knowledge_unit_generation ステップ

### 実装ファイル
- `worker/src/steps/knowledge_unit_generation.py`

### 処理フロー

```
1. clusters テーブルから topic_name, intent, summary を取得
2. cluster_representatives から代表行を取得
3. cluster_centroids から centroid_vector を取得
4. Knowledge Unit レコードを組み立て:
   - topic = clusters.topic_name
   - intent = clusters.intent
   - summary = clusters.summary
   - representative_rows_json = 代表行テキスト配列
   - keywords_json = clusters から取得
   - row_count = clusters.row_count
   - embedding = centroid_vector (pgvector)
   - confidence = clusters.quality_score
   - review_status = 'draft'
5. knowledge_units テーブルに INSERT
6. Job status = completed
```

### knowledge_units テーブルへのマッピング

| KU フィールド (CTO 定義) | DB カラム | 値の取得元 |
|------------------------|----------|-----------|
| topic_name | topic | clusters.topic_name |
| intent | intent | clusters.intent |
| summary | summary | clusters.summary |
| representative_examples[] | representative_rows_json | cluster_representatives.raw_text |
| keywords[] | keywords_json | LLM 生成 |
| cluster_id | cluster_id | clusters.id |
| cluster_size | row_count | clusters.row_count |
| centroid_vector | embedding | cluster_centroids.centroid_vector |
| language | ※ 新規カラム追加が必要 | LLM 検出 |

### Migration 追加
- `knowledge_units` テーブルに `language` カラムを追加

---

## Day 9: Dashboard 拡張 + Export

### Dashboard 追加
- Knowledge Unit 一覧ページ (`/knowledge-units`)
- 各 KU の詳細表示（topic, intent, summary, 代表行, keywords）
- review_status の表示（draft / reviewed / approved）

### Export 機能
- `GET /api/knowledge-units/export?job_id={id}&format=json`
- `GET /api/knowledge-units/export?job_id={id}&format=csv`
- JSON 形式: CTO 定義の Knowledge Unit 構造に準拠
- CSV 形式: フラットなテーブル形式

---

## Day 10: 結合テスト

### テストシナリオ

1. **全パイプライン実行**:
   ```
   CSV → preprocess → embedding → clustering → cluster_analysis → knowledge_unit_generation
   ```
   → knowledge_units テーブルに KU が生成される

2. **Knowledge Unit 品質確認**:
   - topic_name が意味のある名前か
   - intent が適切か
   - summary がクラスタ内容を正しく要約しているか
   - keywords が関連性があるか

3. **Export 検証**:
   - JSON Export が CTO 定義の構造に準拠
   - CSV Export が正しいフォーマット

---

## ステップチェイン更新

```python
# worker/src/step_chain.py
STEP_SEQUENCE = [
    "preprocess",
    "embedding",
    "clustering",
    "cluster_analysis",          # Phase 2 追加
    "knowledge_unit_generation", # Phase 2 追加
]
```

---

## リスクと対策

| リスク | 対策 |
|--------|------|
| Claude Sonnet のレート制限 | 1 クラスタずつ逐次処理 + Exponential backoff |
| LLM 出力が JSON 形式でない | Structured Output 指示 + JSON パース失敗時リトライ |
| LLM のハルシネーション | プロンプトに「入力テキストに基づく情報のみ」を明記 |
| Bedrock Claude の初回利用でユースケース入力 | 画面指示に従って入力 |
| 少数クラスタ（3 件）では KU 品質が低い可能性 | min_cluster_size 調整は Phase 2 改善項目 |

---

## コスト見積（Phase 2 追加分）

| 処理 | 概算 |
|------|------|
| Claude Sonnet 呼び出し（3 クラスタ × 1 回） | ~$0.05 |
| テスト中の再実行（10 回程度） | ~$0.50 |
| Phase 2 合計（テスト含む） | ~$1.00 |

※ クラスタ数が増えてもクラスタ毎 1 回の LLM 呼び出しなので、コスト増は線形で予測可能。

---

*上級エンジニア — 2026-03-24*
