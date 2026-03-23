# 設計図書 v1 レビュー — 上級エンジニア回答

**対象文書**: Chatbot Knowledge Preparation System — 設計図書 CTO Design Package v1
**レビュー担当**: 上級エンジニア
**日付**: 2026-03-23
**ステータス**: 設計承認（条件付き）

---

## エグゼクティブサマリー

設計図書 v1 は、前回のレビュー指摘事項を的確に反映した高品質な設計骨格である。

**総合評価: 承認（条件付き）** — 以下の条件付きで Phase 0 着手を推奨する。

### 評価の高い点
- Knowledge Unit JSON Schema が具体的で、チャットボット投入を見据えた実用的な設計
- ER Diagram が `knowledge_unit_versions` / `knowledge_unit_reviews` を含み、運用フローを考慮
- Job State Machine が明確で、各ステップの入出力・責務が定義済み
- Pipeline Config Schema が再現性を完全に担保する設計
- Laravel Control Plane + Python Data Plane の分離が明確
- ValidateAndEstimate ステップの採用（コスト事故防止）

### 着手前に確認すべき条件（後述）
1. Python Worker ↔ Laravel 間の通信プロトコル確定
2. Fargate タスク設計の詳細（メモリ/CPU レンジ）
3. 認証・テナント分離の初期実装レベル確定

---

## セクション別レビュー

---

## 1. Knowledge Unit JSON Schema — ◎ 良好

### 評価
前回レビューで推奨したフィールドがほぼ網羅されている。`typical_cases` の配列化、`keywords` の追加、`source_refs` の分離は優れた判断。

### 追加推奨事項

#### 1.1 `typical_cases` を配列にした判断は正しい
前回の私のレビューでは `typical_case` (単数) としていたが、CTO が `typical_cases` (配列、最大5件) とした判断のほうが実用的。チャットボット側で複数のバリエーションを表示できる。

#### 1.2 `keywords` フィールドの追加は良い判断
チャットボット側での検索・マッチング精度向上に寄与する。ただし、keywords の生成方法を明確にすべき：

- **推奨**: ClusterAnalysis ステップで TF-IDF ベースのキーワード抽出を行い、LLM に渡して洗練させる2段階方式
- **理由**: LLM 単独ではドメイン固有の重要語を見落とす可能性がある

#### 1.3 `source_refs` の分離は優れた設計
Knowledge Unit 本体と生成元情報を分離することで、KU の再利用性が高まる。

#### 1.4 追加検討すべきフィールド

| フィールド | 理由 | 優先度 |
|-----------|------|--------|
| `language` | 多言語対応の将来拡張。Phase 1 では `ja` 固定でも列だけ用意 | 低 |
| `escalation_flag` | `notes` に「有人対応へエスカレーション」が含まれる場合、構造化して検索可能にする | 中 |
| `last_published_at` | published 状態管理の監査用 | 低 |

#### 1.5 Phase 1 制約の妥当性
「1 Cluster → 1 Knowledge Unit」は妥当。ただし、1 Cluster が複数トピックを含む場合の分割ロジックは Phase 2 で必要になる。この前提をドキュメントに明記しておくべき。

---

## 2. ER Diagram — ◎ 良好（一部補強推奨）

### 評価
前回レビューの推奨テーブル設計をベースに、`knowledge_unit_versions`、`knowledge_unit_reviews`、`exports`、`embedding_cache` が追加され、運用を見据えた完成度の高い設計。

### 補強推奨事項

#### 2.1 `pipeline_configs` と `jobs` の関係
現在の設計では `jobs.pipeline_config_snapshot_json` にスナップショットを保存する方針。これは正しいが、`pipeline_configs` テーブルとの関連を FK で持たせるべき：

```
jobs
  - pipeline_config_id (FK → pipeline_configs, nullable)
  - pipeline_config_snapshot_json
```

**理由**: 「このジョブはどの config テンプレートから派生したか」を追跡可能にする。snapshot_json が実行時の真のソースであることは変わらない。

#### 2.2 `dataset_rows.row_no` の追加は良い判断
元データの行番号を保持することで、エクスポート時に元ファイルとの突合が容易になる。前回レビューで漏れていた点。

#### 2.3 `datasets.schema_json` の追加は良い判断
データセットのカラム構造を保持することで、異なる形式のデータセットに対応可能。

#### 2.4 インデックス設計の明示を推奨
Migration 作成前に、以下のインデックスを設計に含めるべき：

```
dataset_rows:    (dataset_id, tenant_id)、(embedding_hash)
clusters:        (job_id, tenant_id)
cluster_memberships: (cluster_id, dataset_row_id) UNIQUE
knowledge_units: (job_id, tenant_id)、(cluster_id)、(review_status, tenant_id)
embedding_cache: (embedding_hash) UNIQUE
```

#### 2.5 `knowledge_unit_reviews` は Phase 2 で十分
Phase 1 では `knowledge_units.review_status` の直接更新で運用し、レビュー履歴の保存は Phase 2 以降で良い。テーブル定義だけ先に含めるのは問題ない。

---

## 3. Job State Machine — ◎ 良好

### 評価
前回レビューの推奨をほぼ完全に反映。状態一覧、各状態の責務定義、失敗時の方針が明確。

### 追加推奨事項

#### 3.1 `validating` → `awaiting_approval` 状態の追加検討

```
validating
  ├─→ preprocessing（閾値内）
  └─→ awaiting_approval（閾値超過時）
        ├─→ preprocessing（承認）
        └─→ cancelled（却下）
```

CTOが「閾値超過時は承認待ちにしてもよい」と記載しているが、これを正式な状態として定義すべき。コスト事故防止の要。

#### 3.2 進捗率の定義
`jobs.progress` (0-100) について、各ステップの重み付けを定義すべき：

| ステップ | 重み | 累積 |
|---------|------|------|
| validating | 5% | 5% |
| preprocessing | 10% | 15% |
| embedding | 25% | 40% |
| clustering | 20% | 60% |
| cluster_analysis | 15% | 75% |
| knowledge_unit_generation | 15% | 90% |
| exporting | 10% | 100% |

**理由**: ユーザーに意味のある進捗表示を行うため。Embedding が最も時間がかかるためウェイトを大きく取る。

#### 3.3 「同一 job_id 再開よりも新規 job_id 推奨」は正しい判断
べき等性の観点で、再実行は新規ジョブとして管理するほうが安全。`retry_of_job_id` の将来追加も妥当。

#### 3.4 キャンセル可能範囲の明確化
`[submitted|validating|preprocessing] → cancelled` は妥当。Embedding 以降のキャンセルは API コスト発生済みのため、キャンセル不可とする設計は合理的。ただし、Embedding 中のキャンセル要望は発生しうるため、Phase 2 で「未処理分のみ中止」の実装を検討。

---

## 4. Pipeline Config Schema — ◎ 優秀

### 評価
前回レビューで推奨した全パラメータが網羅されており、セクション分けも論理的。**本設計図書で最も完成度が高いセクション**。

### 追加推奨事項

#### 4.1 `llm.model` の値を具体的に
現在 `"model": "claude"` となっているが、Bedrock のモデル ID を正確に記載すべき：

```json
"llm": {
  "provider": "bedrock",
  "model_id": "anthropic.claude-sonnet-4-20250514",
  "temperature": 0.0,
  "max_tokens": 2000
}
```

**理由**: Bedrock はモデルバージョンが頻繁に更新される。再現性のためにモデル ID の完全指定が必須。

#### 4.2 `embedding.model` も同様

```json
"embedding": {
  "provider": "bedrock",
  "model_id": "amazon.titan-embed-text-v2:0",
  "dimension": 1024,
  ...
}
```

#### 4.3 Config のバリデーションスキーマ
Pipeline Config の JSON Schema バリデーションを実装すべき。Laravel 側で `PipelineConfigValidator` を作成し、不正な config でのジョブ投入を防止。

#### 4.4 `clustering.random_seed` を `runtime` から分離検討
現在 `runtime.random_seed` にあるが、クラスタリングの再現性に直結するため `clustering.random_seed` としても良い。ただし、現在の配置でも機能上の問題はない。**低優先度**。

---

## 5. Pipeline Architecture — ◎ 良好

### 評価
7ステップの定義、各ステップの入出力・責務の記述が明確。中間データ保存方針も妥当。

### 追加推奨事項

#### 5.1 ステップ間データ受け渡しのインターフェース定義
各ステップの入出力は定義されているが、**データの物理的な受け渡し方式**を明確にすべき：

```
Step N の出力 → S3 に書き出し → S3 パスを jobs テーブルに記録
                                → Step N+1 が S3 パスを読み込み
```

具体的には `jobs` テーブルに以下のカラム追加を検討：

```
jobs
  - step_outputs_json: {
      "preprocess": {"s3_path": "...", "row_count": 10000},
      "embedding":  {"s3_path": "...", "cache_hits": 8500, "api_calls": 1500},
      "clustering": {"cluster_count": 145, "noise_count": 230},
      ...
    }
```

**理由**: 各ステップの中間結果メタデータを一元管理し、デバッグ・再実行・監査を容易にする。

#### 5.2 Embedding ステップの並列化設計
1000件/チャンクのバッチ分割を推奨。10万件のデータセットなら100チャンクを並列実行。

**Laravel 側**:
```
Laravel Batch Job → 100個の EmbeddingChunkJob を dispatch
各 ChunkJob → Fargate タスクまたは SQS → Python Worker
```

**Python 側**:
```
各 Worker がチャンクを処理 → S3 に結果書き出し
全チャンク完了後 → 結合 → 次ステップへ
```

#### 5.3 ClusterAnalysis と KnowledgeUnitGeneration の境界
両ステップとも LLM を呼び出すため、統合して1ステップにする選択肢もある。ただし、CTO の設計（分離）は以下の理由で妥当：

- ClusterAnalysis は**全クラスタ一括**で処理（トピック一覧の全体像を把握してからの命名）
- KnowledgeUnitGeneration は**クラスタ単位**で処理（各 KU の詳細生成）
- 分離することで ClusterAnalysis の結果をレビューしてから KU 生成に進むオプションが残る

---

## 6. System Architecture — ◎ 良好（通信設計の詳細化が必要）

### 評価
Laravel Control Plane + Python Data Plane の分離が明確。AWS コンポーネントの選定、S3 パス設計、マルチテナント方針が具体的。

### 最重要の追加推奨事項

#### 6.1 Python Worker ↔ Laravel 間の通信プロトコル（着手前に確定必須）

**3つの選択肢**:

| 方式 | 概要 | 推奨度 |
|------|------|--------|
| **A: SQS + DB ポーリング** | Laravel → SQS → Python。Python 完了時に RDS 直接更新 | **Phase 1 推奨** |
| B: SQS + Callback API | Laravel → SQS → Python。Python 完了時に Laravel API を呼び出し | Phase 2 検討 |
| C: ECS RunTask 直接 | Laravel → ECS RunTask API → Python。Python 完了時に RDS 直接更新 | 代替案 |

**方式 A を推奨する理由**:
- Python Worker が Laravel API に依存しない（認証・CSRF 等の問題を回避）
- RDS は両方からアクセス可能
- SQS がバッファとなり、Fargate の起動遅延を吸収
- デッドレターキューで失敗メッセージを保持

**方式 A の実装イメージ**:
```
1. Laravel: Job レコード作成 (status=submitted)
2. Laravel: SQS にメッセージ送信 {job_id, tenant_id, step, s3_input_path}
3. Python: SQS からメッセージ受信
4. Python: 処理実行
5. Python: RDS の jobs テーブルを直接更新 (status, progress, step_outputs_json)
6. Python: 次ステップの SQS メッセージ送信（または自身で次ステップ実行）
7. Laravel: Horizon Worker が DB をポーリングし UI 更新
```

#### 6.2 Fargate タスク設計

**推奨タスク定義**:

| プロファイル | vCPU | メモリ | 用途 |
|------------|------|--------|------|
| small | 2 | 8GB | Preprocess, ClusterAnalysis, KU Generation |
| medium | 4 | 30GB | Embedding, 小〜中規模 Clustering |
| large | 8 | 60GB | 大規模 Clustering (50万件超) |

**重要**: 全プロファイルで **Fargate Spot** を使用。クラスタリングはリトライ可能なため中断耐性あり。

#### 6.3 RDS 接続の分離
Python Worker と Laravel が同じ RDS に接続する設計のため：

- **接続プール管理**: Python 側は `psycopg2` + コネクションプール（短命なタスクなので最小限）
- **書き込み競合防止**: Python は `jobs`, `clusters`, `cluster_memberships`, `knowledge_units` に書き込み。Laravel は `jobs.status` の読み取りが中心。書き込み競合は発生しにくいが、`jobs` テーブルの更新は楽観ロック (`updated_at` チェック) を推奨
- **DB ユーザー分離**: Python Worker 用と Laravel 用で別の DB ユーザーを使用。権限を最小化

#### 6.4 S3 パス設計 — 補足
CTO の設計は十分だが、`embedding-cache` パスの構造を補足：

```
s3://bucket/{tenant_id}/embedding-cache/{embedding_hash}.npy
```

**注意**: テナント横断での embedding 共有は行わない。同一テキストでも tenant_id が異なれば別キャッシュ。セキュリティ > コスト効率。

#### 6.5 マルチテナント — Phase 1 の具体策
CTO が「RLS は Phase 2 以降」としているのは妥当。Phase 1 では：

1. Eloquent Global Scope (`TenantScope` trait) で全モデルにフィルタ適用
2. Python Worker は job 起動時の `tenant_id` パラメータのみ使用
3. S3 パスにテナントプレフィックス強制
4. **テナント越境テスト**: 最低限、`tenant_A のジョブが tenant_B のデータを読めない` テストを CI に含める

---

## 7. 実装優先順位 — ○ 妥当（微調整推奨）

### Phase 構成の評価
CTO の 4 Phase 構成は概ね妥当だが、Phase 1 と Phase 2 の境界を微調整：

### 推奨微調整

```
Phase 0（2週間）: 変更なし — 基盤セットアップ
  + Python Worker ↔ Laravel 通信プロトコルの実装・検証

Phase 1（3週間）: 変更なし — 最小パイプライン
  目標: CSV アップロード → Clustering 結果が DB に保存

Phase 2（3週間）: CTO案を踏襲 + Export を含める
  - ClusterAnalysis
  - KnowledgeUnitGeneration
  - KU の JSON/CSV エクスポート（簡易版）
  - KU Review（review_status の手動更新のみ）
  目標: エンドツーエンドで KU が生成・エクスポートされる

Phase 3（2週間）: 品質・効率化
  - Embedding キャッシュ強化
  - Quality metrics
  - Pipeline Config バリデーション
  - 進捗率表示

Phase 4（3週間）: 本番化
  - マルチテナント強化
  - KU Review UI
  - コスト最適化 (Fargate Spot, Bedrock Batch)
  - 監視・アラート
  - 負荷テスト
```

**変更理由**: Phase 2 で Export の簡易版を含めることで、早期にエンドツーエンドのデモが可能になる。ステークホルダーへの進捗報告に有効。

---

## 8. 設計上の最重要判断 — 全項目に同意

CTO の 7 つの判断すべてに同意。特に以下は本プロジェクトの成否を左右する：

> 7. ValidateAndEstimate によりコスト事故を防ぐ

Embedding API / LLM API のコストは実行件数に比例して増大するため、ValidateAndEstimate は**安全弁**として不可欠。この判断がなければ、テスト中の大規模データセット投入で予想外の請求が発生するリスクがあった。

---

## 着手前チェックリスト

Phase 0 着手前に確定すべき事項：

- [ ] **Python Worker ↔ Laravel 通信方式の確定**（SQS + DB ポーリング を推奨）
- [ ] **Fargate タスク定義のプロファイル** (small/medium/large のスペック)
- [ ] **Bedrock モデル ID の確定**（Titan Embed v2, Claude Sonnet のバージョン固定）
- [ ] **RDS インスタンスタイプの選定**（開発: db.t4g.medium、本番: db.r6g.large 推奨）
- [ ] **Phase 1 の完了基準**（「CSV アップロード → Clustering 結果 DB 保存」のデモ成功）

---

## 次の設計成果物への所見

CTO が「9. 次の設計成果物」として挙げた 6 項目について：

| 成果物 | 優先度 | コメント |
|--------|--------|---------|
| Laravel Migration Draft | **最優先** | Phase 0 着手に直結。本設計図書の ER から直接生成可能 |
| Python Worker Interface Spec | **最優先** | 通信プロトコル確定後すぐに必要 |
| Knowledge Unit Prompt Design | Phase 2 | ClusterAnalysis/KU Generation の品質を決定 |
| ValidateAndEstimate Cost Formula | Phase 1 | Embedding/LLM のトークン概算式を定義 |
| Export Schema Specification | Phase 2 | KU JSON Schema が確定済みなので差分は少ない |
| Phase 0 Implementation Plan | **最優先** | 本レビュー承認後すぐに作成 |

**推奨順序**: Phase 0 Implementation Plan → Laravel Migration Draft → Python Worker Interface Spec → 残り

---

## 総合評価

| セクション | 評価 | コメント |
|-----------|------|---------|
| 1. KU JSON Schema | ◎ | 実用的で網羅的。`keywords`, `source_refs` の追加が良い |
| 2. ER Diagram | ◎ | `knowledge_unit_versions/reviews` の追加が優秀 |
| 3. Job State Machine | ◎ | 各状態の責務が明確。`awaiting_approval` 追加を推奨 |
| 4. Pipeline Config | ◎ | 本設計図書の最高品質セクション |
| 5. Pipeline Architecture | ◎ | ステップ間データ受け渡し方式の明確化を推奨 |
| 6. System Architecture | ○ | 通信プロトコルの詳細化が必要（本レビューで推奨案を提示済み） |
| 7. 実装優先順位 | ○ | Phase 2 に Export 簡易版を含める微調整を推奨 |

**結論: 設計図書 v1 を条件付き承認。Phase 0 着手を推奨する。**

---

*上級エンジニアレビュー完了 — 2026-03-23*
