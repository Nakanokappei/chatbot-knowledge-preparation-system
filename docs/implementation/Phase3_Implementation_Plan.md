# Phase 3 Implementation Plan — Knowledge Dataset Management

**日付**: 2026-03-24
**承認**: CTO_Phase2_Completion_Approval_Phase3_Start.md
**目的**: Knowledge Unit を人間レビュー → 修正 → 承認 → ナレッジデータセット化する

---

## 1. Phase 3 完了基準（CTO 決定、8 項目）

| # | 完了基準 | Day |
|---|---------|-----|
| 1 | Knowledge Unit Review UI | Day 1-2 |
| 2 | Knowledge Unit 編集 | Day 1-2 |
| 3 | review_status 変更 (draft → reviewed → approved → rejected) | Day 1-2 |
| 4 | approved のみ Export | Day 2 |
| 5 | Knowledge Unit Version 履歴 | Day 2-3 |
| 6 | Knowledge Dataset Export | Day 3 |
| 7 | Multi-tenant 分離 | Day 4-5 |
| 8 | Fargate Worker 実行 | Day 6-8 |

---

## 2. 既存インフラ（構築済み）

Phase 3 で活用できる既存資産：

| 資産 | 状態 |
|------|------|
| `knowledge_units` テーブル（review_status, version カラム含む） | ✅ |
| `knowledge_unit_versions` テーブル（snapshot_json） | ✅ |
| `knowledge_unit_reviews` テーブル（immutable audit log） | ✅ |
| `KnowledgeUnit` / `KnowledgeUnitVersion` / `KnowledgeUnitReview` Eloquent モデル | ✅ |
| `BelongsToTenant` トレイト（global scope） | ✅ |
| `User` モデル（tenant_id FK） | ✅ |
| Auth config（Sanctum + session） | ✅ |
| Export エンドポイント（JSON / CSV） | ✅ 改修必要 |

---

## 3. 実装計画

### Day 1-2: Knowledge Unit Review & Edit UI

#### 3.1 KnowledgeUnitController（新規）

**ファイル**: `app/app/Http/Controllers/KnowledgeUnitController.php`

| メソッド | ルート | 機能 |
|---------|--------|------|
| `show($ku)` | `GET /knowledge-units/{ku}` | KU 詳細 + 編集フォーム |
| `update($ku)` | `PUT /knowledge-units/{ku}` | topic, intent, summary, cause_summary, resolution_summary, notes 更新 |
| `review($ku)` | `POST /knowledge-units/{ku}/review` | review_status 変更 + KnowledgeUnitReview レコード作成 |

#### 3.2 KU 詳細/編集画面（新規）

**ファイル**: `app/resources/views/dashboard/knowledge_units/show.blade.php`

レイアウト：
- 上部: topic, intent, review_status バッジ
- 編集フォーム: topic, intent, summary, cause_summary, resolution_summary, notes（全て編集可能）
- レビューボタン: ステータスに応じて表示
  - draft → 「Mark as Reviewed」
  - reviewed → 「Approve」「Reject」
  - rejected → 「Revert to Draft」
- メタ情報: row_count, confidence, version, keywords, created_at
- Typical Cases: 代表テキスト表示
- バージョン履歴リンク

#### 3.3 review_status 遷移ルール

```
draft → reviewed → approved
                 → rejected → draft (再レビュー)
```

- `update()` 時は version をインクリメント + `knowledge_unit_versions` にスナップショット保存
- `review()` 時は `knowledge_unit_reviews` に immutable レコード作成
- reviewer_user_id は Phase 3 前半では `1` 固定（認証実装前）

#### 3.4 ルート追加

**ファイル**: `app/routes/web.php`

```php
Route::get('/knowledge-units/{knowledgeUnit}', [..., 'show'])->name('knowledge-units.show');
Route::put('/knowledge-units/{knowledgeUnit}', [..., 'update'])->name('knowledge-units.update');
Route::post('/knowledge-units/{knowledgeUnit}/review', [..., 'review'])->name('knowledge-units.review');
```

#### 3.5 KU 一覧ページの更新

**ファイル**: `app/resources/views/dashboard/knowledge_units.blade.php`

- 各 KU カードに「Edit / Review」リンク追加
- review_status フィルター（All / Draft / Reviewed / Approved）

---

### Day 2-3: Approved-Only Export & Versioning UI

#### 3.6 Export 改修

**ファイル**: `app/app/Http/Controllers/DashboardController.php` — `exportKnowledgeUnits()`

- デフォルトで `review_status = 'approved'` のみ Export
- クエリパラメータ `status=all` で全件 Export も可能
- Export 画面に「Approved only (3)」「All (6)」の選択肢

#### 3.7 バージョン履歴画面（新規）

**ファイル**: `app/resources/views/dashboard/knowledge_units/versions.blade.php`

| メソッド | ルート | 機能 |
|---------|--------|------|
| `versions($ku)` | `GET /knowledge-units/{ku}/versions` | バージョン一覧 |

レイアウト：
- バージョン番号、作成日時、snapshot_json の diff（前バージョンとの差分をハイライト）
- 各バージョンの snapshot 内容を展開表示

---

### Day 4-5: Multi-tenant 分離

#### 3.8 認証 UI（簡易実装）

**ファイル**:
- `app/resources/views/auth/login.blade.php` — ログインフォーム
- `app/app/Http/Controllers/AuthController.php` — login / logout

Phase 3 では招待制（register なし）。既存 User + Tenant レコードを tinker で手動作成。

#### 3.9 Auth Middleware 適用

**ファイル**: `app/routes/web.php`

```php
Route::middleware('auth')->group(function () {
    // 既存の全ルートをここに移動
});
```

#### 3.10 hardcoded tenant_id の除去

**影響ファイル**:
- `DashboardController.php` — `$tenantId = 1` → `auth()->user()->tenant_id`
- `SettingsController.php` — 同上
- `KnowledgeUnitController.php` — 同上

#### 3.11 RLS（PostgreSQL Row Level Security）

DB レベルのセーフティネットとして、主要テーブルに RLS ポリシーを追加：

```sql
ALTER TABLE knowledge_units ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON knowledge_units
  USING (tenant_id = current_setting('app.tenant_id')::bigint);
```

Laravel 側でコネクション確立時に `SET app.tenant_id = {tenant_id}` を実行。

---

### Day 6-8: Fargate Worker 実行

#### 3.12 Docker 化

**新規ファイル**:
- `worker/Dockerfile` — Python 3.13 + boto3 + 依存関係
- `app/Dockerfile` — PHP 8.3 + Laravel + nginx
- `docker-compose.yml` — ローカル開発用（Laravel, Worker, PostgreSQL）

#### 3.13 ECS Task Definition

**新規ファイル**:
- `infra/ecs-task-worker.json` — Worker タスク定義
  - CPU: 1 vCPU, Memory: 4GB
  - Fargate Spot 使用
  - 環境変数: SQS_QUEUE_URL, DB 接続情報, AWS credentials

#### 3.14 Worker の SQS ポーリングモード確認

`worker/src/main.py` の既存 SQS ポーリングループを Fargate 環境で動作確認：
- Long polling (20s wait)
- Graceful shutdown (SIGTERM handling)
- Visibility timeout 管理

#### 3.15 デプロイ手順書

**新規ファイル**: `docs/deployment/Fargate_Deployment_Guide.md`

---

## 4. ファイル変更一覧

### 新規ファイル

| ファイル | 内容 |
|---------|------|
| `app/Http/Controllers/KnowledgeUnitController.php` | KU 詳細/編集/レビュー |
| `app/Http/Controllers/AuthController.php` | ログイン/ログアウト |
| `resources/views/dashboard/knowledge_units/show.blade.php` | KU 詳細/編集画面 |
| `resources/views/dashboard/knowledge_units/versions.blade.php` | バージョン履歴 |
| `resources/views/auth/login.blade.php` | ログインフォーム |
| `worker/Dockerfile` | Worker コンテナ |
| `app/Dockerfile` | Laravel コンテナ |
| `docker-compose.yml` | ローカル開発環境 |
| `infra/ecs-task-worker.json` | ECS タスク定義 |

### 変更ファイル

| ファイル | 変更内容 |
|---------|---------|
| `routes/web.php` | KU ルート + auth middleware |
| `DashboardController.php` | Export 改修 + tenant_id 動的化 |
| `SettingsController.php` | tenant_id 動的化 |
| `knowledge_units.blade.php` | Edit/Review リンク + status フィルター |

---

## 5. リスクと対策

| リスク | 対策 |
|--------|------|
| RLS 導入でクエリが遅くなる | テナントカラムにインデックス済み。テスト後に有効化 |
| Fargate コールドスタート | Spot + minimum 1 desired で対応。Phase 3 では許容 |
| 認証なしの既存機能が壊れる | auth middleware 適用前にログイン UI を完成させる |
| Docker 化で .env 管理が複雑化 | ECS Parameter Store + Secrets Manager を使用 |

---

## 6. 実装順序の根拠

1. **Review UI を最初に**（Day 1-2）: ユーザー価値が最も高い。認証なしでも動作確認可能
2. **Export 改修 + Versioning**（Day 2-3）: Review UI と密結合。Review 完了後に Export で検証
3. **Multi-tenant**（Day 4-5）: 機能が全て揃った後にセキュリティを追加。順序を逆にすると全機能の開発が遅くなる
4. **Fargate**（Day 6-8）: 全機能完成後にインフラ移行。本番デプロイの最終ステップ

---

*上級エンジニア — 2026-03-24*
