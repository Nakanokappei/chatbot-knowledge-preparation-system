<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CostController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\KnowledgeDatasetController;
use App\Http\Controllers\KnowledgeUnitController;
use App\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

// Authentication (public)
Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login']);
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

// All application routes require authentication
Route::middleware('auth')->group(function () {

    // Dashboard
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/jobs/{pipelineJob}', [DashboardController::class, 'show'])->name('dashboard.show');
    Route::post('/dispatch', [DashboardController::class, 'dispatch'])->name('dashboard.dispatch');
    Route::post('/dispatch-pipeline', [DashboardController::class, 'dispatchPipeline'])->name('dashboard.dispatch-pipeline');
    Route::get('/jobs/{pipelineJob}/knowledge-units', [DashboardController::class, 'knowledgeUnits'])->name('dashboard.knowledge-units');
    Route::get('/jobs/{pipelineJob}/knowledge-units/export', [DashboardController::class, 'exportKnowledgeUnits'])->name('dashboard.knowledge-units.export');

    // Knowledge Unit: detail, edit, review, versions
    Route::get('/knowledge-units/{knowledgeUnit}', [KnowledgeUnitController::class, 'show'])->name('knowledge-units.show');
    Route::put('/knowledge-units/{knowledgeUnit}', [KnowledgeUnitController::class, 'update'])->name('knowledge-units.update');
    Route::post('/knowledge-units/{knowledgeUnit}/review', [KnowledgeUnitController::class, 'review'])->name('knowledge-units.review');
    Route::get('/knowledge-units/{knowledgeUnit}/versions', [KnowledgeUnitController::class, 'versions'])->name('knowledge-units.versions');

    // Knowledge Datasets
    Route::get('/datasets', [KnowledgeDatasetController::class, 'index'])->name('datasets.index');
    Route::get('/datasets/create', [KnowledgeDatasetController::class, 'create'])->name('datasets.create');
    Route::post('/datasets', [KnowledgeDatasetController::class, 'store'])->name('datasets.store');
    Route::get('/datasets/{dataset}', [KnowledgeDatasetController::class, 'show'])->name('datasets.show');
    Route::post('/datasets/{dataset}/publish', [KnowledgeDatasetController::class, 'publish'])->name('datasets.publish');
    Route::post('/datasets/{dataset}/new-version', [KnowledgeDatasetController::class, 'newVersion'])->name('datasets.new-version');
    Route::get('/datasets/{dataset}/export', [KnowledgeDatasetController::class, 'export'])->name('datasets.export');
    Route::get('/datasets/{dataset}/chat', [KnowledgeDatasetController::class, 'chat'])->name('datasets.chat');
    Route::get('/datasets/{dataset}/evaluation', [KnowledgeDatasetController::class, 'evaluation'])->name('datasets.evaluation');

    // Cost dashboard
    Route::get('/cost', [CostController::class, 'index'])->name('cost');

    // Settings: LLM model management
    Route::get('/settings/models', [SettingsController::class, 'index'])->name('settings.models');
    Route::post('/settings/models', [SettingsController::class, 'store'])->name('settings.models.store');
    Route::put('/settings/models/{llmModel}', [SettingsController::class, 'update'])->name('settings.models.update');
    Route::delete('/settings/models/{llmModel}', [SettingsController::class, 'destroy'])->name('settings.models.destroy');
});
