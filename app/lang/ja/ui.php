<?php

/**
 * Japanese UI strings for the Knowledge Preparation System.
 *
 * Organized by page/section for easy maintenance.
 */
return [
    // ── Common / Shared ─────────────────────────────────
    'app_name' => 'ナレッジ準備システム',
    'logout' => 'ログアウト',
    'login' => 'ログイン',
    'email' => 'メールアドレス',
    'password' => 'パスワード',
    'remember_me' => 'ログイン状態を保持',
    'save' => '保存',
    'cancel' => 'キャンセル',
    'delete' => '削除',
    'add' => '追加',
    'edit' => '編集',
    'close' => '閉じる',
    'send' => '送信',
    'back' => '戻る',
    'rows' => '行',
    'enabled' => '有効',
    'disabled' => '無効',

    // ── Top navigation ──────────────────────────────────
    'nav_workspace' => 'ワークスペース',
    'nav_settings' => '設定',
    'nav_profile' => 'プロフィール',

    // ── Sidebar: Tree view ──────────────────────────────
    'upload_csv' => 'CSV アップロード',
    'no_embeddings' => '埋め込みなし',
    'create_embedding' => '埋め込みを作成',
    'no_datasets' => 'データセットがありません',
    'delete_dataset' => 'このデータセットを削除',
    'confirm_delete_dataset' => 'このデータセットと全行を削除しますか？この操作は元に戻せません。',

    // ── Sidebar: Pipeline ───────────────────────────────
    'pipeline' => 'パイプライン',
    'all_jobs' => 'すべてのジョブ',
    'completed' => '完了',
    'processing' => '処理中',
    'failed' => '失敗',
    'run_pipeline' => 'パイプライン実行',

    // ── Workspace: Cluster header ───────────────────────
    'clusters' => 'クラスター',
    'noise' => 'ノイズ',
    'silhouette' => 'シルエット',
    'method' => '手法',
    'lang_debias' => '言語バイアス除去',
    'llm' => 'LLM',
    'created' => '作成日時',
    'chat_with_data' => 'データと会話',
    'rename' => '名前変更',
    'delete_embedding' => '削除',
    'export' => 'エクスポート',

    // ── Workspace: Cluster list ─────────────────────────
    'select_all' => 'すべて選択',
    'set_draft' => '下書きに設定',
    'set_reviewed' => 'レビュー済みに設定',
    'set_approved' => '承認済みに設定',
    'set_rejected' => '却下に設定',
    'no_clusters_yet' => 'クラスターがありません',
    'run_pipeline_to_generate' => 'パイプラインを実行してクラスターを生成してください。',
    'select_embedding' => '埋め込みを選択',
    'select_embedding_hint' => 'サイドバーから埋め込みを選択して、クラスターを表示します。',

    // ── Workspace: Cluster detail ───────────────────────
    'topic' => 'トピック',
    'intent' => '意図',
    'summary' => '要約',
    'keywords' => 'キーワード',
    'representative_rows' => '代表行',
    'row_number' => '行番号',
    'distance' => '距離',
    'raw_text' => 'テキスト',
    'review_status' => 'ステータス',
    'back_to_list' => '← 一覧に戻る',

    // ── Workspace: Chat overlay ─────────────────────────
    'chat' => 'チャット',
    'type_message' => 'メッセージを入力...',
    'thinking' => '考え中...',
    'sources' => '参照元',

    // ── Pipeline job list ───────────────────────────────
    'no_jobs' => 'ジョブがありません',
    'no_jobs_hint' => 'パイプラインを実行してください。',
    'no_jobs_filter' => '":filter" のジョブはありません。',
    'noise_points' => 'ノイズポイント',

    // ── Upload / Configure dataset ──────────────────────
    'upload_and_configure' => 'アップロードして設定',
    'after_upload_hint' => 'アップロード後、列の選択、クラスタリング手法、パイプライン設定を行います。',
    'configure_dataset' => 'データセット設定',
    'basic_settings' => '基本設定',
    'dataset_name' => 'データセット名',
    'first_row_is' => '1行目の扱い',
    'header_option' => '見出し行（列名）',
    'data_option' => 'データ行（見出しなし）',
    'encoding' => '文字コード',
    'select_columns' => '埋め込み用の列を選択',
    'select_columns_hint' => '+ をクリックして列を追加。ドラッグで並び替え。ラベルを編集できます。',
    'available_columns' => '利用可能な列',
    'embedding_columns' => '埋め込み列（順序付き）',
    'drop_here' => '列をここにドロップするか、左の + をクリック',
    'embedding_preview' => '埋め込みテキスト プレビュー',
    'select_columns_preview' => '上で列を選択するとプレビューが表示されます...',
    'pipeline_settings' => 'パイプライン設定',
    'llm_model' => 'LLM モデル',
    'clustering_method' => 'クラスタリング手法',
    'remove_language_bias' => '言語バイアスを除去（多言語データ推奨）',
    'start_pipeline' => '🚀 パイプライン開始',
    'test_pipeline' => '🧪 テスト処理（最大500件）',
    'embedding_model' => '埋め込みモデル',
    'select_one_column' => '列を1つ以上選択してください',
    'columns_selected' => ':count 列を選択中',

    // ── Settings: Models ────────────────────────────────
    'llm_models' => 'LLM モデル',
    'llm_models_desc' => 'クラスター分析に使用するモデルを管理します。',
    'add_model' => 'AWS Bedrock からモデルを追加',
    'select_model' => 'モデルを選択',
    'choose_model' => '-- モデルを選択 --',
    'display_name' => '表示名',
    'display_name_auto' => '表示名（自動入力）',
    'registered_models' => '登録済みモデル',
    'no_models' => 'モデルが登録されていません。',
    'model_id' => 'モデル ID',
    'input_cost' => '入力コスト',
    'output_cost' => '出力コスト',
    'status' => 'ステータス',
    'default' => 'デフォルト',
    'active' => '有効',
    'inactive' => '無効',
    'set_default' => 'デフォルトに設定',
    'deactivate' => '無効化',
    'activate' => '有効化',
    'embedding_models' => '埋め込みモデル',
    'embedding_models_desc' => 'ベクトル生成に使用する埋め込みモデルを管理します。',
    'add_embedding_model' => 'AWS Bedrock から埋め込みモデルを追加',
    'choose_embedding_model' => '-- 埋め込みモデルを選択 --',
    'dimension' => '次元数',
    'cost' => 'コスト',
    'no_embedding_models' => '埋め込みモデルが登録されていません。',

    // ── Silhouette evaluation ───────────────────────────
    'silhouette_excellent' => '優良',
    'silhouette_good' => '良好',
    'silhouette_typical' => 'テキストでは標準的',
    'silhouette_typical_text' => '標準的（テキスト）',
    'silhouette_poor' => '要改善',

    // ── Profile ─────────────────────────────────────────
    'profile' => 'プロフィール',
    'change_password' => 'パスワード変更',
    'current_password' => '現在のパスワード',
    'new_password' => '新しいパスワード',
    'confirm_password' => 'パスワード確認',
    'update_profile' => 'プロフィール更新',
    'name' => '名前',
];
