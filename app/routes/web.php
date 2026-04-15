<?php

/**
 * Web route definitions for the Chatbot Knowledge Preparation System.
 *
 * Routes are organized into public (auth, locale) and authenticated
 * groups. The authenticated group contains workspace, pipeline,
 * dataset wizard, knowledge unit, knowledge dataset, cost dashboard,
 * profile, and settings routes.
 */

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AdminSettingsController;
use App\Http\Controllers\AdminUsageController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\UsageController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\WorkspaceController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DatasetWizardController;
use App\Http\Controllers\EmbeddingController;
use App\Http\Controllers\EmbedApiKeyController;
use App\Http\Controllers\EmbedChatController;
use App\Http\Controllers\EmbedController;
use App\Http\Controllers\QuestionInsightsController;
use App\Http\Controllers\KnowledgePackageController;
use App\Http\Controllers\KnowledgeUnitController;
use App\Http\Controllers\ManualKnowledgeUnitController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

// Locale switching (no auth required)
Route::get('/locale/{locale}', function (string $locale) {
    if (in_array($locale, ['en', 'ja'])) {
        session(['locale' => $locale]);
    }
    return redirect()->back();
})->name('locale.switch');

// First-run setup: create the initial system administrator
Route::get('/setup', [\App\Http\Controllers\SetupController::class, 'show'])->name('setup');
Route::post('/setup', [\App\Http\Controllers\SetupController::class, 'createAdmin']);

// Authentication (public)
// Login POST is throttled ('login' limiter in AppServiceProvider) so a
// credential-spraying bot can't probe one account endlessly.
Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

// Password reset (public). Throttle the "send link" and "submit reset"
// endpoints separately: the former protects user enumeration and inbox
// flooding, the latter protects the random token from brute-force.
Route::get('/forgot-password', [PasswordResetController::class, 'showForgotForm'])->name('password.request');
Route::post('/forgot-password', [PasswordResetController::class, 'sendResetLink'])
    ->middleware('throttle:forgot-password')
    ->name('password.email');
Route::get('/reset-password/{token}', [PasswordResetController::class, 'showResetForm'])->name('password.reset');
Route::post('/reset-password', [PasswordResetController::class, 'resetPassword'])
    ->middleware('throttle:reset-password')
    ->name('password.update');

// Invitation registration (public — accessed via emailed link).
// Throttled to slow token-guessing when the allowlist is removed.
Route::get('/invitation/{token}', [InvitationController::class, 'showRegisterForm'])
    ->middleware('throttle:invitation')
    ->name('invitation.register');
Route::post('/invitation/{token}', [InvitationController::class, 'register'])
    ->middleware('throttle:invitation');

// ── Embed routes (public — no session/Sanctum auth) ──────────────
// Chat page served in iframe (API key is the URL token)
Route::get('/embed/chat/{token}', [EmbedController::class, 'show'])->name('embed.chat');
// Demo inquiry page with chat widget (dynamically rendered)
Route::get('/embed/demo/{token}', [EmbedController::class, 'demo'])->name('embed.demo');
// Chat API called from within the iframe (API key in Authorization header)
Route::post('/embed/api/chat', [EmbedChatController::class, 'chat'])
    ->name('embed.api.chat')
    ->middleware(['embed.apikey', 'throttle:60,1']);
// CORS preflight for embed API (no auth required for OPTIONS)
Route::options('/embed/api/chat', fn () => response('', 204)
    ->header('Access-Control-Allow-Origin', '*')
    ->header('Access-Control-Allow-Methods', 'POST, OPTIONS')
    ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Embed-Key')
    ->header('Access-Control-Max-Age', '3600'));

// All application routes require authentication
Route::middleware('auth')->group(function () {

    // Workspace status page (shown when frozen or suspended)
    Route::get('/workspace-status', fn () => view('workspace.status'))->name('workspace.status');

    // Workspace (main view — sidebar with embeddings + KU list)
    Route::get('/', [EmbeddingController::class, 'index'])->name('workspace.index');
    Route::get('/workspace/{embeddingId}', [EmbeddingController::class, 'index'])->name('workspace.embedding');
    Route::get('/workspace/{embeddingId}/ku/{kuId}', [EmbeddingController::class, 'showKnowledgeUnit'])->name('workspace.ku');
    Route::post('/workspace/{embeddingId}/bulk-approve', [EmbeddingController::class, 'bulkApprove'])->name('workspace.bulk-approve');
    Route::post('/workspace/{embeddingId}/bulk-status', [EmbeddingController::class, 'bulkUpdateStatus'])->name('workspace.ku.bulk-status');
    Route::put('/workspace/{embeddingId}/rename', [EmbeddingController::class, 'rename'])->name('workspace.rename');
    Route::post('/workspace/{embeddingId}/recluster', [EmbeddingController::class, 'recluster'])->name('workspace.recluster');
    Route::post('/workspace/{embeddingId}/parameter-search', [EmbeddingController::class, 'parameterSearch'])->name('workspace.parameter-search');
    Route::get('/workspace/{embeddingId}/parameter-search-results', [EmbeddingController::class, 'parameterSearchResults'])->name('workspace.parameter-search-results');
    Route::post('/workspace/{embeddingId}/dismiss-param-search', [EmbeddingController::class, 'dismissParameterSearch'])->name('workspace.dismiss-param-search');
    Route::delete('/workspace/{embeddingId}', [EmbeddingController::class, 'destroy'])->name('workspace.destroy');
    Route::post('/workspace/cleanup-jobs', [EmbeddingController::class, 'cleanupJobs'])->name('workspace.cleanup-jobs');
    Route::get('/workspace/{embeddingId}/export', [EmbeddingController::class, 'export'])->name('workspace.export');
    Route::get('/workspace/{embeddingId}/export-rows', [EmbeddingController::class, 'exportWithClusters'])->name('workspace.export-rows');
    Route::post('/workspace/{embeddingId}/chat', [\App\Http\Controllers\EmbeddingChatController::class, 'chat'])->name('workspace.chat');
    Route::post('/workspace/{embeddingId}/chat-feedback', [\App\Http\Controllers\EmbeddingChatController::class, 'feedback'])->name('workspace.chat-feedback');
    Route::get('/workspace/{embeddingId}/chat-sessions', [\App\Http\Controllers\EmbeddingChatController::class, 'sessions'])->name('workspace.chat-sessions');
    Route::get('/workspace/{embeddingId}/chat-sessions/{session}', [\App\Http\Controllers\EmbeddingChatController::class, 'sessionDetail'])->name('workspace.chat-session');

    // Pipeline: redirect legacy URLs to workspace pipeline view
    Route::get('/pipeline', fn () => redirect('/?pipeline=jobs&pf=all'))->name('dashboard');
    Route::get('/pipeline/jobs/{pipelineJob}', fn ($pipelineJob) => redirect('/?pipeline=jobs&pf=all'))->name('dashboard.show');
    // Dataset wizard and pipeline routes — workspace-scoped (system_admin blocked)
    Route::middleware('redirect_sysadmin')->group(function () {
        Route::post('/dataset/upload', [DatasetWizardController::class, 'upload'])->name('dataset.upload');
        Route::get('/dataset/{dataset}/configure', [DatasetWizardController::class, 'configure'])->name('dataset.configure');
        Route::post('/dataset/{dataset}/preview', [DatasetWizardController::class, 'preview'])->name('dataset.preview');
        Route::post('/dataset/{dataset}/re-encode', [DatasetWizardController::class, 'reEncode'])->name('dataset.re-encode');
        Route::post('/dataset/{dataset}/generate-descriptions', [DatasetWizardController::class, 'generateDescriptionsApi'])->name('dataset.generate-descriptions');
        Route::post('/dataset/{dataset}/finalize', [DatasetWizardController::class, 'finalize'])->name('dataset.finalize');
        Route::put('/dataset/{dataset}/rename', [DatasetWizardController::class, 'rename'])->name('dataset.rename');
        Route::delete('/dataset/{dataset}', [DatasetWizardController::class, 'destroy'])->name('dataset.destroy');

        Route::post('/dispatch-pipeline', [DashboardController::class, 'dispatchPipeline'])->name('dashboard.dispatch-pipeline');
        Route::post('/jobs/{pipelineJob}/cancel', [DashboardController::class, 'cancelPipeline'])->name('dashboard.cancel-pipeline');
        Route::post('/jobs/{pipelineJob}/retry', [DashboardController::class, 'retryJob'])->name('dashboard.retry-job');
        Route::delete('/jobs/{pipelineJob}', [DashboardController::class, 'destroyJob'])->name('dashboard.delete-job');
        Route::get('/jobs/{pipelineJob}/knowledge-units', [DashboardController::class, 'knowledgeUnits'])->name('dashboard.knowledge-units');
        Route::get('/jobs/{pipelineJob}/knowledge-units/export', [DashboardController::class, 'exportKnowledgeUnits'])->name('dashboard.knowledge-units.export');

        // Manual QA registration: create KU without pipeline
        Route::get('/knowledge-units/create', [ManualKnowledgeUnitController::class, 'create'])->name('knowledge-units.create');
        Route::post('/knowledge-units', [ManualKnowledgeUnitController::class, 'store'])->name('knowledge-units.store');

        // Knowledge Unit: detail, edit, versions (owner+member); review is owner-only
        Route::get('/knowledge-units/{knowledgeUnit}', [KnowledgeUnitController::class, 'show'])->name('knowledge-units.show');
        Route::put('/knowledge-units/{knowledgeUnit}', [KnowledgeUnitController::class, 'update'])->name('knowledge-units.update');
        Route::get('/knowledge-units/{knowledgeUnit}/versions', [KnowledgeUnitController::class, 'versions'])->name('knowledge-units.versions');
        Route::post('/jobs/{pipelineJob}/knowledge-units/bulk-approve', [KnowledgeUnitController::class, 'bulkApprove'])->name('knowledge-units.bulk-approve');
    });

    // Knowledge Unit review — owner only (system_admin redirected, member → 403)
    Route::post('/knowledge-units/{knowledgeUnit}/review', [KnowledgeUnitController::class, 'review'])
        ->name('knowledge-units.review')
        ->middleware('workspace_owner');

    // Knowledge Packages — owner only (system_admin redirected, member → 403)
    Route::middleware('workspace_owner')->group(function () {
        Route::get('/knowledge-packages', [KnowledgePackageController::class, 'index'])->name('kp.index');
        Route::get('/knowledge-packages/create', [KnowledgePackageController::class, 'create'])->name('kp.create');
        Route::post('/knowledge-packages', [KnowledgePackageController::class, 'store'])->name('kp.store');
        Route::get('/knowledge-packages/{package}', [KnowledgePackageController::class, 'show'])->name('kp.show');
        Route::post('/knowledge-packages/{package}/submit-review', [KnowledgePackageController::class, 'submitForReview'])->name('kp.submit-review');
        Route::post('/knowledge-packages/{package}/publish', [KnowledgePackageController::class, 'publish'])->name('kp.publish');
        Route::post('/knowledge-packages/{package}/reject-review', [KnowledgePackageController::class, 'rejectReview'])->name('kp.reject-review');
        Route::post('/knowledge-packages/{package}/new-version', [KnowledgePackageController::class, 'newVersion'])->name('kp.new-version');
        Route::post('/knowledge-packages/{package}/refresh-kus', [KnowledgePackageController::class, 'refreshKUs'])->name('kp.refresh-kus');
        Route::get('/knowledge-packages/{package}/export', [KnowledgePackageController::class, 'export'])->name('kp.export');
        Route::get('/knowledge-packages/{package}/export-faq', [KnowledgePackageController::class, 'exportFaq'])->name('kp.export-faq');
        Route::put('/knowledge-packages/{package}/embed-config', [KnowledgePackageController::class, 'updateEmbedConfig'])->name('kp.embed-config');
        Route::get('/knowledge-packages/{package}/chat', [KnowledgePackageController::class, 'chat'])->name('kp.chat');
        Route::get('/knowledge-packages/{package}/evaluation', [KnowledgePackageController::class, 'evaluation'])->name('kp.evaluation');
        Route::delete('/knowledge-packages/{package}', [KnowledgePackageController::class, 'destroy'])->name('kp.destroy');
        Route::post('/knowledge-packages/{package}/upload-icon', [KnowledgePackageController::class, 'uploadIcon'])->name('kp.upload-icon');

        // Embed API key management (per-package)
        Route::get('/knowledge-packages/{package}/api-keys', [EmbedApiKeyController::class, 'index'])->name('kp.api-keys.index');
        Route::post('/knowledge-packages/{package}/api-keys', [EmbedApiKeyController::class, 'store'])->name('kp.api-keys.store');
        Route::delete('/api-keys/{apiKey}', [EmbedApiKeyController::class, 'revoke'])->name('api-keys.revoke');
        Route::delete('/api-keys/{apiKey}/destroy', [EmbedApiKeyController::class, 'destroy'])->name('api-keys.destroy');
    });

    // Usage dashboard — owner only (system_admin redirected, member → 403)
    Route::get('/usage', [UsageController::class, 'index'])->name('usage')->middleware('workspace_owner');

    // Question Insights — owner only
    Route::get('/question-insights', [QuestionInsightsController::class, 'index'])
        ->name('question-insights.index')
        ->middleware('workspace_owner');

    // Chat & Retrieve (web session auth, used by browser UI)
    Route::post('/web-api/retrieve', [\App\Http\Controllers\Api\RetrievalController::class, 'retrieve'])->name('web.retrieve');
    Route::post('/web-api/chat', [\App\Http\Controllers\Api\ChatController::class, 'chat'])->name('web.chat');

    // API guide — workspace-scoped (system_admin blocked)
    Route::get('/api-guide', [\App\Http\Controllers\ApiGuideController::class, 'index'])
        ->name('api.guide')
        ->middleware('redirect_sysadmin');

    // Profile: user settings, password change, API tokens
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('/profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');
    Route::get('/profile/tokens', [ProfileController::class, 'tokens'])->name('profile.tokens');
    Route::post('/profile/tokens', [ProfileController::class, 'createToken'])->name('profile.tokens.create');
    Route::delete('/profile/tokens/{tokenId}', [ProfileController::class, 'revokeToken'])->name('profile.tokens.revoke');

    // System admin routes: cross-workspace management
    Route::middleware('system_admin')->prefix('admin')->group(function () {
        Route::get('/', [AdminController::class, 'index'])->name('admin.index');
        Route::get('/workspaces/create', [AdminController::class, 'createWorkspace'])->name('admin.workspaces.create');
        Route::post('/workspaces', [AdminController::class, 'storeWorkspace'])->name('admin.workspaces.store');
        Route::delete('/workspaces/{workspace}', [AdminController::class, 'destroyWorkspace'])->name('admin.workspaces.destroy');
        Route::put('/workspaces/{workspace}/status', [AdminController::class, 'updateWorkspaceStatus'])->name('admin.workspaces.status');
        Route::post('/workspaces/{workspace}/invite', [AdminController::class, 'inviteToWorkspace'])->name('admin.workspaces.invite');
        Route::delete('/invitations/{invitation}', [AdminController::class, 'cancelInvitation'])->name('admin.invitations.cancel');
        Route::post('/jobs/{pipelineJob}/cancel', [AdminController::class, 'cancelJob'])->name('admin.cancel-pipeline');
        Route::get('/system', [AdminController::class, 'systemHealth'])->name('admin.system');

        // Admin usage: aggregate and per-workspace
        Route::get('/usage', [AdminUsageController::class, 'index'])->name('admin.usage');
        Route::get('/usage/{workspace}', [AdminUsageController::class, 'show'])->name('admin.usage.workspace');

        // Admin settings: system-level model templates
        Route::get('/settings', [AdminSettingsController::class, 'index'])->name('admin.settings.index');
        Route::post('/settings', [AdminSettingsController::class, 'store'])->name('admin.settings.store');
        Route::put('/settings/{llmModel}', [AdminSettingsController::class, 'update'])->name('admin.settings.update');
        Route::delete('/settings/{llmModel}', [AdminSettingsController::class, 'destroy'])->name('admin.settings.destroy');
        Route::post('/settings/embedding-models', [AdminSettingsController::class, 'storeEmbedding'])->name('admin.settings.embedding.store');
        Route::put('/settings/embedding-models/{embeddingModel}', [AdminSettingsController::class, 'updateEmbedding'])->name('admin.settings.embedding.update');
        Route::delete('/settings/embedding-models/{embeddingModel}', [AdminSettingsController::class, 'destroyEmbedding'])->name('admin.settings.embedding.destroy');

        // OpenAI settings
        Route::post('/settings/openai-key', [AdminSettingsController::class, 'saveOpenAiKey'])->name('admin.settings.openai-key');
        Route::post('/settings/openai-embedding', [AdminSettingsController::class, 'storeOpenAiEmbedding'])->name('admin.settings.openai-embedding.store');
    });

    // Owner-only routes: workspace settings, model management, invitations
    Route::middleware('owner')->group(function () {
        // Workspace settings
        Route::get('/settings/workspace', [WorkspaceController::class, 'edit'])->name('workspace.settings');
        Route::put('/settings/workspace', [WorkspaceController::class, 'update'])->name('workspace.update');
        Route::put('/settings/workspace/users/{user}/role', [WorkspaceController::class, 'updateRole'])->name('workspace.update-role');

        // Password reset on behalf of a member
        Route::post('/settings/workspace/users/{user}/reset-password', [WorkspaceController::class, 'sendPasswordReset'])->name('workspace.reset-password');

        // Member invitation
        Route::post('/profile/invite', [InvitationController::class, 'send'])->name('invitation.send');
        Route::delete('/invitation/{invitation}/cancel', [InvitationController::class, 'cancel'])->name('invitation.cancel');

        // Settings: LLM model management
        Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
        Route::post('/settings', [SettingsController::class, 'store'])->name('settings.store');
        Route::put('/settings/{llmModel}', [SettingsController::class, 'update'])->name('settings.update');
        Route::delete('/settings/{llmModel}', [SettingsController::class, 'destroy'])->name('settings.destroy');

        // Embedding model management
        Route::post('/settings/embedding-models', [SettingsController::class, 'storeEmbedding'])->name('settings.embedding.store');
        Route::put('/settings/embedding-models/{embeddingModel}', [SettingsController::class, 'updateEmbedding'])->name('settings.embedding.update');
        Route::delete('/settings/embedding-models/{embeddingModel}', [SettingsController::class, 'destroyEmbedding'])->name('settings.embedding.destroy');
    });
});
