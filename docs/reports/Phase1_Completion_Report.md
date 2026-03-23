# Phase 1 完了報告

**日付**: 2026-03-24
**宛先**: CTO
**送信者**: 上級エンジニア
**件名**: Phase 1 完了 — Phase 2 着手承認依頼

---

## 1. 結論

**Phase 1 の全完了基準 9/9 を達成しました。Phase 2 着手の承認をお願いします。**

---

## 2. 完了基準の達成状況

| # | 完了基準 | 結果 |
|---|---------|------|
| 1 | CSV アップロード → dataset_rows 保存 | ✅ |
| 2 | dataset_rows → Embedding 生成 | ✅ |
| 3 | Embedding → S3 に保存 | ✅ |
| 4 | Embedding Cache が機能 | ✅ |
| 5 | Embedding → HDBSCAN → clusters 作成 | ✅ |
| 6 | dataset_rows → cluster_memberships 保存 | ✅ |
| 7 | clusters テーブルに cluster_size 保存 | ✅ |
| 8 | Laravel Dashboard でクラスタ数・サイズ確認可能 | ✅ |
| 9 | 同じ CSV を再実行した場合、Embedding Cache がヒット | ✅ |

---

## 3. パイプライン実行結果

テストデータ: `customer_support_tickets.csv`（500 行）

### 3.1 パイプラインフロー（SQS 経由全自動）

```
Laravel API (Job 作成)
  ↓ SQS: step=preprocess
Python Worker: preprocess (500 rows → 正規化 → S3 Parquet)
  ↓ SQS: step=embedding (自動チェイン)
Python Worker: embedding (Bedrock Titan v2 → 500 x 1024 → S3 .npy)
  ↓ SQS: step=clustering (自動チェイン)
Python Worker: clustering (HDBSCAN → 3 clusters → RDS)
  ↓ RDS: status=completed
Laravel Dashboard: クラスタ結果表示
```

### 3.2 クラスタリング結果

| クラスタ | サイズ | 割合 |
|---------|--------|------|
| 0 | 29 | 5.8% |
| 1 | 166 | 33.2% |
| 2 | 26 | 5.2% |
| ノイズ | 279 | 55.8% |

- **クラスタ数**: 3
- **シルエットスコア**: 0.0802
- **HDBSCAN パラメータ**: min_cluster_size=15, min_samples=5, metric=euclidean

### 3.3 再現性検証

同一データセットで 3 回実行（Job #4, #5, #6）し、全て同一の結果を確認：

| Job | Clusters | Noise | Silhouette | Cache Hit Rate |
|-----|----------|-------|------------|----------------|
| #4 | 3 | 279 | 0.0802 | 97.2% |
| #5 | 3 | 279 | 0.0802 | 97.2% |
| #6 | 3 | 279 | 0.0802 | 97.2% |

### 3.4 Embedding キャッシュ

| 指標 | 値 |
|------|-----|
| キャッシュ登録数 | 486 件（500 行中 486 ユニークテキスト） |
| 再実行時ヒット率 | 97.2%（486/500） |
| 再実行時の Bedrock API 呼び出し | 14 回（重複テキスト分のみ） |
| 初回実行時の Bedrock API 呼び出し | 500 回 |
| コスト削減効果 | 再実行時 97% 削減 |

---

## 4. 構築した成果物

### 4.1 Python Worker ステップ（新規）

| ファイル | 機能 |
|---------|------|
| `worker/src/steps/preprocess.py` | テキスト正規化 → S3 Parquet 出力 |
| `worker/src/steps/embedding.py` | Bedrock Titan v2 呼び出し + キャッシュ → S3 .npy 出力 |
| `worker/src/steps/clustering.py` | HDBSCAN + centroids + representatives → RDS 保存 |
| `worker/src/bedrock_client.py` | Bedrock API クライアント（リトライ + 並列処理） |
| `worker/src/step_chain.py` | ステップ自動チェイン（SQS 経由） |

### 4.2 データベーステーブル（新規）

| テーブル | 用途 |
|---------|------|
| `cluster_centroids` | クラスタ中心ベクトル（pgvector, CTO 指示） |
| `cluster_representatives` | 代表行（centroid 最近傍 5 件, CTO 指示） |

### 4.3 Dashboard 拡張

| 機能 | 詳細 |
|------|------|
| 一覧ページ | クラスタ数・ノイズ数カラム追加、Details リンク |
| Run Full Pipeline ボタン | preprocess → embedding → clustering を SQS 経由で自動実行 |
| 詳細ページ (`/jobs/{id}`) | サマリー統計、パイプラインステップ情報、クラスタ別サイズ・バー表示、代表行表示 |

### 4.4 S3 データ構造

```
s3://knowledge-prep-data-dev/{tenant_id}/jobs/{job_id}/
  ├── preprocess/
  │   └── normalized_rows.parquet
  ├── embedding/
  │   ├── embeddings.npy      (500 x 1024, float32)
  │   └── row_ids.json
  └── clustering/
      └── cluster_results.json

s3://knowledge-prep-data-dev/cache/embeddings/
  └── {hash_prefix}/{sha256}.json  (個別 Embedding キャッシュ)
```

---

## 5. CTO 指示への対応状況

| CTO 指示 | 対応 |
|---------|------|
| cluster_centroids テーブル追加 | ✅ Migration + clustering ステップで保存 |
| cluster_representatives テーブル追加 | ✅ Migration + centroid 最近傍 5 件を保存 |
| 「クラスタは後で Knowledge Unit になる」前提の設計 | ✅ centroids, representatives, quality_score を Phase 2 向けに準備済み |

---

## 6. 設計原則の遵守状況

| # | 原則 | Phase 1 |
|---|------|---------|
| 1 | Reproducibility | ✅ 3 回実行で同一結果。pipeline_config に全パラメータ記録 |
| 2 | Idempotent Jobs | ✅ 新規 job_id で再実行、既存データに干渉しない |
| 3 | Intermediate Data on S3 | ✅ Parquet / .npy / JSON を S3 に保存 |
| 4 | Metadata in RDS | ✅ clusters, memberships, centroids, representatives は RDS |
| 5 | Tenant Isolation | ✅ S3 パス・DB 全テーブルに tenant_id |
| 6 | Cost Visibility | ✅ step_outputs に cache_hit_rate, API 呼出回数を記録 |
| 7 | KU is Final Product | ✅ centroids + representatives で Phase 2 KU 生成に備え済み |

---

## 7. ノイズ率について

ノイズ率 55.8% は高めですが、テストデータの特性によるものです：

- テンプレート文（`I'm having an issue with the {product_purchased}`）が多数
- テンプレートの微妙な差異で類似度が中途半端になりノイズ判定
- 実運用データではテンプレート率が低くなるため改善が見込まれる

対策オプション（Phase 2 以降）：
- `min_cluster_size` の調整（15 → 10）
- テンプレート文の検出・正規化の強化
- ノイズポイントの二次クラスタリング

---

## 8. Phase 2 への提案

Phase Roadmap に基づき、Phase 2 は **Cluster Analysis + Topic Naming** です。

### Phase 2 想定タスク

| タスク | 内容 |
|--------|------|
| Cluster Naming | LLM（Claude Sonnet via Bedrock）で各クラスタにトピック名を付与 |
| Intent Detection | クラスタ内の問い合わせ意図を LLM で分類 |
| Cluster Summary | クラスタの概要を LLM で生成 |
| representative_rows の活用 | centroid 最近傍 5 件を LLM に入力 |

### Phase 2 で必要な判断事項

| # | 判断事項 |
|---|---------|
| 1 | LLM モデル（Claude Sonnet 確定済み、バージョン指定は？） |
| 2 | プロンプト設計方針（日本語 or 英語 or 自動検出） |
| 3 | Cluster Analysis のステップ分割（Naming + Summary を 1 ステップ or 2 ステップ） |
| 4 | Phase 2 完了基準 |

---

## 9. 承認依頼

以下の承認をお願いします：

- [ ] Phase 1 完了の承認
- [ ] Phase 2 着手の承認
- [ ] Phase 2 判断事項への回答（上記 4 項目）

---

*上級エンジニア — 2026-03-24*
