<?php

/**
 * Web route definitions for the Chatbot Knowledge Preparation System.
 *
 * Routes are organized into public (auth, locale) and authenticated
 * groups. The authenticated group contains workspace, pipeline,
 * dataset wizard, knowledge unit, knowledge dataset, cost dashboard,
 * profile, and settings routes.
 */

use App\Http\Controllers\AuthController;
use App\Http\Controllers\UsageController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\WorkspaceController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DatasetWizardController;
use App\Http\Controllers\EmbeddingController;
use App\Http\Controllers\KnowledgeDatasetController;
use App\Http\Controllers\KnowledgeUnitController;
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

// Authentication (public)
Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login']);
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

// Password reset (public)
Route::get('/forgot-password', [PasswordResetController::class, 'showForgotForm'])->name('password.request');
Route::post('/forgot-password', [PasswordResetController::class, 'sendResetLink'])->name('password.email');
Route::get('/reset-password/{token}', [PasswordResetController::class, 'showResetForm'])->name('password.reset');
Route::post('/reset-password', [PasswordResetController::class, 'resetPassword'])->name('password.update');

// Invitation registration (public — accessed via emailed link)
Route::get('/invitation/{token}', [InvitationController::class, 'showRegisterForm'])->name('invitation.register');
Route::post('/invitation/{token}', [InvitationController::class, 'register']);

// All application routes require authentication
Route::middleware('auth')->group(function () {

    // Workspace (main view — sidebar with embeddings + KU list)
    Route::get('/', [EmbeddingController::class, 'index'])->name('workspace.index');
    Route::get('/workspace/{embeddingId}', [EmbeddingController::class, 'index'])->name('workspace.embedding');
    Route::get('/workspace/{embeddingId}/ku/{kuId}', [EmbeddingController::class, 'showKnowledgeUnit'])->name('workspace.ku');
    Route::post('/workspace/{embeddingId}/bulk-approve', [EmbeddingController::class, 'bulkApprove'])->name('workspace.bulk-approve');
    Route::post('/workspace/{embeddingId}/bulk-status', [EmbeddingController::class, 'bulkUpdateStatus'])->name('workspace.ku.bulk-status');
    Route::put('/workspace/{embeddingId}/rename', [EmbeddingController::class, 'rename'])->name('workspace.rename');
    Route::delete('/workspace/{embeddingId}', [EmbeddingController::class, 'destroy'])->name('workspace.destroy');
    Route::post('/workspace/cleanup-jobs', [EmbeddingController::class, 'cleanupJobs'])->name('workspace.cleanup-jobs');
    Route::get('/workspace/{embeddingId}/export', [EmbeddingController::class, 'export'])->name('workspace.export');
    Route::get('/workspace/{embeddingId}/export-rows', [EmbeddingController::class, 'exportWithClusters'])->name('workspace.export-rows');
    Route::post('/workspace/{embeddingId}/chat', [\App\Http\Controllers\EmbeddingChatController::class, 'chat'])->name('workspace.chat');
    Route::post('/workspace/{embeddingId}/chat-feedback', [\App\Http\Controllers\EmbeddingChatController::class, 'feedback'])->name('workspace.chat-feedback');

    // Pipeline: redirect legacy URLs to workspace pipeline view
    Route::get('/pipeline', fn () => redirect('/?pipeline=jobs&pf=all'))->name('dashboard');
    Route::get('/pipeline/jobs/{pipelineJob}', fn ($pipelineJob) => redirect('/?pipeline=jobs&pf=all'))->name('dashboard.show');
    // Dataset wizard: upload → configure → finalize
    Route::post('/dataset/upload', [DatasetWizardController::class, 'upload'])->name('dataset.upload');
    Route::get('/dataset/{dataset}/configure', [DatasetWizardController::class, 'configure'])->name('dataset.configure');
    Route::post('/dataset/{dataset}/preview', [DatasetWizardController::class, 'preview'])->name('dataset.preview');
    Route::post('/dataset/{dataset}/re-encode', [DatasetWizardController::class, 'reEncode'])->name('dataset.re-encode');
    Route::post('/dataset/{dataset}/generate-descriptions', [DatasetWizardController::class, 'generateDescriptionsApi'])->name('dataset.generate-descriptions');
    Route::post('/dataset/{dataset}/finalize', [DatasetWizardController::class, 'finalize'])->name('dataset.finalize');
    Route::delete('/dataset/{dataset}', [DatasetWizardController::class, 'destroy'])->name('dataset.destroy');

    Route::post('/dispatch-pipeline', [DashboardController::class, 'dispatchPipeline'])->name('dashboard.dispatch-pipeline');
    Route::post('/jobs/{pipelineJob}/cancel', [DashboardController::class, 'cancelPipeline'])->name('dashboard.cancel-pipeline');
    Route::get('/jobs/{pipelineJob}/knowledge-units', [DashboardController::class, 'knowledgeUnits'])->name('dashboard.knowledge-units');
    Route::get('/jobs/{pipelineJob}/knowledge-units/export', [DashboardController::class, 'exportKnowledgeUnits'])->name('dashboard.knowledge-units.export');

    // Knowledge Unit: detail, edit, review, versions
    Route::get('/knowledge-units/{knowledgeUnit}', [KnowledgeUnitController::class, 'show'])->name('knowledge-units.show');
    Route::put('/knowledge-units/{knowledgeUnit}', [KnowledgeUnitController::class, 'update'])->name('knowledge-units.update');
    Route::post('/knowledge-units/{knowledgeUnit}/review', [KnowledgeUnitController::class, 'review'])->name('knowledge-units.review');
    Route::get('/knowledge-units/{knowledgeUnit}/versions', [KnowledgeUnitController::class, 'versions'])->name('knowledge-units.versions');
    Route::post('/jobs/{pipelineJob}/knowledge-units/bulk-approve', [KnowledgeUnitController::class, 'bulkApprove'])->name('knowledge-units.bulk-approve');

    // Knowledge Datasets
    Route::get('/knowledge-datasets', [KnowledgeDatasetController::class, 'index'])->name('kd.index');
    Route::get('/knowledge-datasets/create', [KnowledgeDatasetController::class, 'create'])->name('kd.create');
    Route::post('/knowledge-datasets', [KnowledgeDatasetController::class, 'store'])->name('kd.store');
    Route::get('/knowledge-datasets/{dataset}', [KnowledgeDatasetController::class, 'show'])->name('kd.show');
    Route::post('/knowledge-datasets/{dataset}/publish', [KnowledgeDatasetController::class, 'publish'])->name('kd.publish');
    Route::post('/knowledge-datasets/{dataset}/new-version', [KnowledgeDatasetController::class, 'newVersion'])->name('kd.new-version');
    Route::get('/knowledge-datasets/{dataset}/export', [KnowledgeDatasetController::class, 'export'])->name('kd.export');
    Route::get('/knowledge-datasets/{dataset}/chat', [KnowledgeDatasetController::class, 'chat'])->name('kd.chat');
    Route::get('/knowledge-datasets/{dataset}/evaluation', [KnowledgeDatasetController::class, 'evaluation'])->name('kd.evaluation');

    // Usage dashboard
    Route::get('/usage', [UsageController::class, 'index'])->name('usage');

    // Chat & Retrieve (web session auth, used by browser UI)
    Route::post('/web-api/retrieve', [\App\Http\Controllers\Api\RetrievalController::class, 'retrieve'])->name('web.retrieve');
    Route::post('/web-api/chat', [\App\Http\Controllers\Api\ChatController::class, 'chat'])->name('web.chat');

    // Profile: user settings, password change
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('/profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');

    // Owner-only routes: workspace settings, model management, invitations
    Route::middleware('owner')->group(function () {
        // Workspace settings
        Route::get('/settings/workspace', [WorkspaceController::class, 'edit'])->name('workspace.settings');
        Route::put('/settings/workspace', [WorkspaceController::class, 'update'])->name('workspace.update');
        Route::put('/settings/workspace/users/{user}/role', [WorkspaceController::class, 'updateRole'])->name('workspace.update-role');

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
