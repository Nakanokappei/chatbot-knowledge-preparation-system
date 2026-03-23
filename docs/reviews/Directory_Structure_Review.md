# ディレクトリ構成レビュー — 上級エンジニア回答

**日付**: 2026-03-23
**ステータス**: 承認（軽微な追加提案あり）

---

## 総合評価: ◎ 承認

CTO提案のディレクトリ構成は、設計ドキュメントの成長に耐えうる優れた分類体系。即採用を推奨する。

---

## 評価ポイント

### 優れている点

1. **ADR（Architecture Decision Records）の独立ディレクトリ**
   - 技術選定の「なぜ」を構造的に記録できる
   - 後からチームに加わるメンバーへの説明コスト大幅減
   - 番号付き命名規則で時系列追跡が容易

2. **architecture / specs の分離**
   - architecture = システムの「構造」（How it's built）
   - specs = 入出力の「仕様」（What it produces）
   - 関心の分離が明確

3. **implementation / reports / reviews の三層**
   - implementation = 計画（Before）
   - reports = 実績（After）
   - reviews = 評価（Assessment）
   - Phase 単位のライフサイクルが追跡可能

---

## 追加提案

### 1. `decisions/` を `adr/` の別名として検討（低優先度）

`adr/` は技術者には馴染みがあるが、非エンジニアのステークホルダーが読む場合は `decisions/` のほうが直感的。ただし、ADR はエンジニアリングの標準的な用語であり、**現行の `adr/` で問題なし**。

### 2. `archive/` の追加を推奨

```
docs/
├── archive/
│   ├── Implementation_Review_Request.md       ← 初期ドラフト
│   ├── CTO_Implementation_Review_Report.md    ← 初回レビュー
│   └── Design_Package_Review_v1.md            ← 設計図書レビュー
```

**理由**: 設計図書 v1 は現在 architecture/ と specs/ に分解される。分解前の統合ドキュメントと初期のやり取りは、経緯の記録として archive に保存しておくと有用。

### 3. 初期 ADR 候補の追加

CTO提案の 4 件に加え、以下も ADR 化を推奨：

| ADR | タイトル | 理由 |
|-----|---------|------|
| ADR_0005 | SQS + DB Polling for Worker Communication | Laravel ↔ Python 通信方式の選定根拠 |
| ADR_0006 | Bedrock over OpenAI | LLM/Embedding プロバイダー選定根拠 |
| ADR_0007 | Laravel Job Chain over Step Functions | Phase 1 オーケストレーション選定根拠 |

### 4. `specs/` に追加すべきファイル

```
specs/
├── Knowledge_Unit_JSON_Schema.md
├── Pipeline_Config_Schema.md
├── Export_Schema.md
├── Python_Worker_Interface_Spec.md    ← 追加推奨
└── ValidateAndEstimate_Formula.md     ← 追加推奨
```

---

## 既存ファイルの再配置マッピング

現在の `docs/` フラットファイルを新構成にマッピング：

| 現在のファイル | 移動先 | 備考 |
|--------------|--------|------|
| `Implementation_Review_Request.md` | `archive/` | 初期ドラフト（経緯記録） |
| `CTO_Implementation_Review_Report.md` | `archive/` | 初回レビュー（経緯記録） |
| `Chatbot_Knowledge_Preparation_Design_Package.md` | 分解 → `architecture/` + `specs/` | 設計図書の各セクションを独立ファイルに |
| `Design_Package_Review_v1.md` | `reviews/Architecture_Review.md` | リネーム |

### 設計図書の分解マッピング

`Chatbot_Knowledge_Preparation_Design_Package.md` のセクションを分解：

| セクション | 移動先 |
|-----------|--------|
| §0 基本方針 | `architecture/System_Architecture.md` に統合 |
| §1 KU JSON Schema | `specs/Knowledge_Unit_JSON_Schema.md` |
| §2 ER Diagram | `architecture/ER_Diagram.md` |
| §3 Job State Machine | `architecture/Job_State_Machine.md` |
| §4 Pipeline Config | `specs/Pipeline_Config_Schema.md` |
| §5 Pipeline Architecture | `architecture/Pipeline_Architecture.md` |
| §6 System Architecture | `architecture/System_Architecture.md` |
| §7 実装優先順位 | `implementation/Phase0_Implementation_Plan.md` の前段 |
| §8 最重要判断 | ADR 群に分散 |
| §9 次の成果物 | `implementation/` のバックログ |
| §10 結論 | `architecture/System_Architecture.md` に統合 |

---

## 最終構成案

```
docs/
│
├── architecture/
│   ├── System_Architecture.md          ← §0 + §6 + §10
│   ├── Pipeline_Architecture.md        ← §5
│   ├── ER_Diagram.md                   ← §2
│   └── Job_State_Machine.md            ← §3
│
├── specs/
│   ├── Knowledge_Unit_JSON_Schema.md   ← §1
│   ├── Pipeline_Config_Schema.md       ← §4
│   ├── Export_Schema.md                ← Phase 2 で作成
│   └── Python_Worker_Interface_Spec.md ← Phase 0 で作成
│
├── adr/
│   ├── ADR_0001_Laravel_Control_Plane.md
│   ├── ADR_0002_Fargate_Data_Plane.md
│   ├── ADR_0003_pgvector.md
│   ├── ADR_0004_HDBSCAN.md
│   ├── ADR_0005_SQS_DB_Polling.md       ← 追加推奨
│   ├── ADR_0006_Bedrock_Provider.md      ← 追加推奨
│   └── ADR_0007_Job_Chain_over_StepFunctions.md  ← 追加推奨
│
├── implementation/
│   ├── Phase0_Implementation_Plan.md
│   ├── Phase1_Implementation_Plan.md
│   └── Phase2_Implementation_Plan.md
│
├── reports/
│   ├── Phase0_Completion_Report.md
│   ├── Phase1_Completion_Report.md
│   └── Phase2_Completion_Report.md
│
├── reviews/
│   ├── Architecture_Review.md           ← Design_Package_Review_v1.md リネーム
│   ├── Directory_Structure_Review.md    ← 本ファイル
│   ├── Phase0_Review.md
│   └── Phase1_Review.md
│
└── archive/
    ├── Implementation_Review_Request.md
    ├── CTO_Implementation_Review_Report.md
    └── Chatbot_Knowledge_Preparation_Design_Package.md
```

---

## 次のアクション

1. **CTO判断待ち**: 本構成案を承認いただければ、ディレクトリ作成とファイル再配置を実施
2. **設計図書の分解**: CTOまたはエンジニアが `Chatbot_Knowledge_Preparation_Design_Package.md` を各ファイルに分解
3. **ADR テンプレート確定**: ADR のフォーマット（Context / Decision / Consequences）を統一

---

*上級エンジニアレビュー完了 — 2026-03-23*
