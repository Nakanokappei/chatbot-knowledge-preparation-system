<?php

/**
 * English UI strings for the Knowledge Preparation System.
 *
 * Organized by page/section for easy maintenance.
 */
return [
    // ── Common / Shared ─────────────────────────────────
    'app_name' => 'Knowledge Preparation System',
    'logout' => 'Logout',
    'login' => 'Login',
    'email' => 'Email',
    'password' => 'Password',
    'remember_me' => 'Remember me',
    'save' => 'Save',
    'cancel' => 'Cancel',
    'delete' => 'Delete',
    'add' => 'Add',
    'edit' => 'Edit',
    'close' => 'Close',
    'send' => 'Send',
    'back' => 'Back',
    'rows' => 'rows',
    'enabled' => 'Enabled',
    'disabled' => 'Disabled',

    // ── Top navigation ──────────────────────────────────
    'nav_workspace' => 'Workspace',
    'nav_settings' => 'Settings',
    'nav_profile' => 'Profile',

    // ── Sidebar: Tree view ──────────────────────────────
    'upload_csv' => 'Upload CSV',
    'no_embeddings' => 'No embeddings',
    'create_embedding' => 'Create embedding',
    'no_datasets' => 'No datasets yet.',
    'delete_dataset' => 'Delete this dataset',
    'confirm_delete_dataset' => 'Delete this dataset and all its rows? This cannot be undone.',

    // ── Sidebar: Pipeline ───────────────────────────────
    'pipeline' => 'Pipeline',
    'all_jobs' => 'All Jobs',
    'completed' => 'Completed',
    'processing' => 'Processing',
    'failed' => 'Failed',
    'run_pipeline' => 'Run Pipeline',

    // ── Workspace: Cluster header ───────────────────────
    'clusters' => 'Clusters',
    'noise' => 'Noise',
    'silhouette' => 'Silhouette',
    'method' => 'Method',
    'lang_debias' => 'Lang Debias',
    'llm' => 'LLM',
    'created' => 'Created',
    'chat_with_data' => 'Chat with this data',
    'rename' => 'Rename',
    'delete_embedding' => 'Delete',
    'export' => 'Export',

    // ── Workspace: Cluster list ─────────────────────────
    'select_all' => 'Select all',
    'set_draft' => 'Set Draft',
    'set_reviewed' => 'Set Reviewed',
    'set_approved' => 'Set Approved',
    'set_rejected' => 'Set Rejected',
    'no_clusters_yet' => 'No clusters yet',
    'run_pipeline_to_generate' => 'Run the pipeline to generate clusters from this embedding.',
    'select_embedding' => 'Select an embedding',
    'select_embedding_hint' => 'Choose an embedding from the sidebar to view its clusters.',

    // ── Workspace: Cluster detail ───────────────────────
    'topic' => 'Topic',
    'intent' => 'Intent',
    'summary' => 'Summary',
    'keywords' => 'Keywords',
    'representative_rows' => 'Representative Rows',
    'row_number' => 'Row #',
    'distance' => 'Distance',
    'raw_text' => 'Text',
    'review_status' => 'Status',
    'back_to_list' => '← Back to list',

    // ── Workspace: Chat overlay ─────────────────────────
    'chat' => 'Chat',
    'type_message' => 'Type a message...',
    'thinking' => 'Thinking...',
    'sources' => 'Sources',

    // ── Pipeline job list ───────────────────────────────
    'no_jobs' => 'No jobs',
    'no_jobs_hint' => 'Run a pipeline to get started.',
    'no_jobs_filter' => 'No jobs with status ":filter".',
    'noise_points' => 'noise points',

    // ── Upload / Configure dataset ──────────────────────
    'upload_and_configure' => 'Upload & Configure',
    'after_upload_hint' => 'After uploading, you\'ll configure columns, clustering method, and pipeline settings.',
    'configure_dataset' => 'Configure Dataset',
    'new_dataset' => 'New Dataset',
    'basic_settings' => 'Basic Settings',
    'dataset_name' => 'Dataset name',
    'first_row_is' => 'First row is',
    'header_option' => 'Header (column names)',
    'data_option' => 'Data (no header)',
    'encoding' => 'Character encoding',
    'select_columns' => 'Select Columns for Embedding',
    'select_columns_hint' => 'Click + to add columns. Drag to reorder. Edit the label shown before each value.',
    'available_columns' => 'Available Columns',
    'embedding_columns' => 'Embedding Columns (in order)',
    'drop_here' => 'Drop columns here or click + on the left',
    'embedding_preview' => 'Embedding Text Preview',
    'select_columns_preview' => 'Select columns above to see preview...',
    'pipeline_settings' => 'Pipeline Settings',
    'llm_model' => 'LLM Model',
    'clustering_method' => 'Clustering Method',
    'remove_language_bias' => 'Remove language bias (recommended for multilingual data)',
    'start_pipeline' => '🚀 Start Pipeline',
    'test_pipeline' => '🧪 Test (max 500 rows)',
    'embedding_model' => 'Embedding Model',
    'select_one_column' => 'Select at least one column',
    'columns_selected' => ':count column(s) selected',

    // ── Settings: Models ────────────────────────────────
    'llm_models' => 'LLM Models',
    'llm_models_desc' => 'Manage available models for cluster analysis.',
    'add_model' => 'Add Model from AWS Bedrock',
    'select_model' => 'Select Model',
    'choose_model' => '-- Choose a model --',
    'display_name' => 'Display Name',
    'display_name_auto' => 'Display Name (auto-filled)',
    'registered_models' => 'Registered Models',
    'no_models' => 'No models registered yet.',
    'model_id' => 'Model ID',
    'input_cost' => 'Input Cost',
    'output_cost' => 'Output Cost',
    'status' => 'Status',
    'default' => 'Default',
    'active' => 'Active',
    'inactive' => 'Inactive',
    'set_default' => 'Set Default',
    'deactivate' => 'Deactivate',
    'activate' => 'Activate',
    'embedding_models' => 'Embedding Models',
    'embedding_models_desc' => 'Manage available embedding models for vector generation.',
    'add_embedding_model' => 'Add Embedding Model from AWS Bedrock',
    'choose_embedding_model' => '-- Choose an embedding model --',
    'dimension' => 'Dimension',
    'cost' => 'Cost',
    'no_embedding_models' => 'No embedding models registered yet.',

    // ── Silhouette evaluation ───────────────────────────
    'silhouette_excellent' => 'Excellent',
    'silhouette_good' => 'Good',
    'silhouette_typical' => 'Typical for text',
    'silhouette_typical_text' => 'Typical (text)',
    'silhouette_poor' => 'Poor',

    // ── Profile ─────────────────────────────────────────
    'profile' => 'Profile',
    'change_password' => 'Change Password',
    'current_password' => 'Current Password',
    'new_password' => 'New Password',
    'confirm_password' => 'Confirm Password',
    'update_profile' => 'Update Profile',
    'name' => 'Name',
];
