
# CTO Review — Phase 1 Kickoff & Implementation Plan Approval

**Date:** 2026-03-24  
**From:** CTO  
**To:** Senior Engineer  
**Subject:** Phase 1 Kickoff Confirmation and Implementation Plan Review

---

## 1. Phase 1 Kickoff Confirmation

Phase 1 着手確認および Phase 1 実装計画を確認しました。

結論として、

**Phase 1 実装計画を承認します。**

ステップ分割、S3 中間データ設計、Embedding キャッシュ設計、ステップチェイン設計のいずれも、
本システムの設計原則に整合しています。

---

## 2. Architecture Consistency Check

今回の Phase 1 設計が、システムの基本アーキテクチャと整合しているか確認しました。

### Pipeline Flow

preprocess  
→ embedding  
→ clustering  

### Data Flow

dataset_rows  
→ normalized_text  
→ embeddings  
→ clusters  
→ cluster_memberships  

### Storage

| データ | 保存先 |
|-------|-------|
| Raw CSV | S3 |
| Normalized Rows | S3 (Parquet) |
| Embeddings | S3 (.npy) |
| Clustering Results | RDS + S3 |
| Metadata | RDS |

これは **Metadata in RDS / Data in S3** の設計原則に完全に一致しています。

設計として問題ありません。

---

## 3. Technical Review

### 3.1 Preprocess → Parquet
これは良い判断です。

理由：
- Parquet は列指向で高速
- pandas / pyarrow との相性が良い
- 将来 Spark / Athena でも読める
- CSV より I/O が軽い

### 3.2 Embedding → NumPy (.npy)
これも良いです。

理由：
- ベクトル配列として扱いやすい
- HDBSCAN へ直接入力できる
- pickle より安全
- JSON より圧倒的に軽い

### 3.3 HDBSCAN
Phase 1 のクラスタリングとして適切です。

特にテキストクラスタリングでは：

- K-Means → クラスタ数事前指定
- DBSCAN → ε 調整が難しい
- HDBSCAN → 自動クラスタ数 + ノイズ検出

HDBSCAN が最も適しています。

---

## 4. Important Design Recommendation

Phase 1 の段階で、以下を追加することを推奨します。

### 4.1 cluster_centroids テーブル

将来の機能のために、クラスタ中心ベクトルを保存してください。

```
cluster_centroids
  id
  cluster_id
  centroid_vector VECTOR(1024)
  created_at
```

理由：
- 新しいデータのクラスタ分類
- クラスタ間距離計算
- クラスタ可視化
- 類似クラスタ統合
- Knowledge Unit 生成時の代表文抽出

これは Phase 2 で必ず使います。

---

### 4.2 representative_rows テーブル

各クラスタの代表文を保存するテーブル。

```
cluster_representatives
  id
  cluster_id
  dataset_row_id
  distance_to_centroid
  rank
```

Knowledge Unit 生成時に：
- クラスタの代表的な問い合わせ
- トピック命名
- 要約生成

に使用します。

---

## 5. Very Important — System Purpose Reminder

このシステムの最終目的を再確認します。

### このシステムの最終目的

**チャットボット用ナレッジの生成**

つまり、

CSV（問い合わせ履歴）  
→ クラスタリング  
→ トピック化  
→ 要約  
→ Knowledge Unit  
→ チャットボット RAG  

Phase 1 はまだ途中段階であり、最終成果物は：

**Knowledge Unit Dataset**

です。

したがって、Phase 1 の設計でも常に：

**「このクラスタは後で Knowledge Unit になる」**

という前提でデータモデルを設計してください。

これは非常に重要です。

---

## 6. Phase Roadmap Reminder

| Phase | 内容 |
|------|------|
| Phase 0 | Pipeline Infrastructure |
| Phase 1 | Embedding + Clustering |
| Phase 2 | Cluster Analysis + Topic Naming |
| Phase 3 | Knowledge Unit Generation |
| Phase 4 | Export / Chatbot Integration |

---

## 7. Final CTO Decision

| 項目 | 決定 |
|------|------|
| Phase 1 Implementation Plan | 承認 |
| Step Structure | 承認 |
| S3 Intermediate Design | 承認 |
| Embedding Cache Design | 承認 |
| Step Chain | 承認 |
| HDBSCAN | 承認 |
| 追加テーブル | cluster_centroids / cluster_representatives |

---

## 8. Instruction

**Phase 1 Implementation を計画通り進めてください。**

