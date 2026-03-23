# ADR-0007: Phase 1 オーケストレーションに Laravel Job Chain を採用

**ステータス**: 提案（CTO承認待ち）
**日付**: 2026-03-23
**提案者**: 上級エンジニア

---

## Context

パイプラインの 7 ステップ（ValidateAndEstimate → ... → ExportAndFinalize）を順序制御する仕組みが必要。AWS Step Functions と Laravel Job Chain が主な候補。

## 選択肢

| 観点 | Laravel Job Chain | Step Functions |
|------|------------------|----------------|
| 実装コスト | 低（Laravel 内で完結） | 中（CDK / CloudFormation 必要） |
| 可視化 | Horizon Dashboard | Step Functions Console |
| エラーハンドリング | Laravel 標準リトライ | Catch / Retry 定義 |
| 並列実行 | Laravel Batch | Map State |
| 複雑なワークフロー分岐 | 弱い | 強い（Choice, Parallel） |
| 学習コスト | 低（Laravel 開発者に馴染み） | 中〜高 |

## Decision

**Phase 1〜2 は Laravel Job Chain + SQS を採用する。Step Functions は将来の移行先として選択肢を残す。**

### 理由
1. 初期開発速度の優先 — Laravel 内で完結しインフラ追加なし
2. 本システムのパイプラインは単純な直列実行（分岐なし）であり、Job Chain で十分
3. 並列化が必要な Embedding ステップは Laravel Batch で対応可能
4. Step Functions への移行は、パイプラインが複雑化した場合（条件分岐・並列ステップ追加時）に検討

## Consequences

### メリット
- Phase 0 のインフラ構築が軽量化
- Laravel 開発者のみで実装可能
- Horizon でジョブの状態・リトライを可視化

### デメリット
- 複雑なワークフロー分岐には不向き（Phase 1 では不要）
- Step Functions のような視覚的なワークフロー図がない

### 移行条件
以下のいずれかが発生した場合、Step Functions への移行を検討する：
- パイプラインに条件分岐が追加される
- ステップ数が 10 を超える
- 複数パイプラインの並行実行管理が必要になる

---

## 関連
- ADR-0001: Laravel Control Plane
- ADR-0005: SQS + DB Polling
