<?php

use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\DatasetController;
use App\Http\Controllers\Api\PipelineJobController;
use App\Http\Controllers\Api\RetrievalController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| All routes require Sanctum authentication and workspace association.
| Routes are prefixed with /api automatically by Laravel.
|
| Token abilities are enforced only for personal access token requests;
| session-authenticated requests (browser UI) bypass ability checks.
|
*/

// Authenticated user info
Route::get('/user', function (Request $request) {
    return $request->user()->load('workspace');
})->middleware('auth:sanctum');

// Protected API routes requiring authentication
Route::middleware('auth:sanctum')->group(function () {

    // Dataset management — scoped by ability
    Route::get('/datasets', [DatasetController::class, 'index'])
        ->middleware('ability:datasets:read');
    Route::get('/datasets/{dataset}', [DatasetController::class, 'show'])
        ->middleware('ability:datasets:read');
    Route::post('/datasets', [DatasetController::class, 'store'])
        ->middleware('ability:datasets:write');

    // Pipeline job management — scoped by ability
    Route::get('/pipeline-jobs', [PipelineJobController::class, 'index'])
        ->middleware('ability:pipeline-jobs:read');
    Route::get('/pipeline-jobs/{pipeline_job}', [PipelineJobController::class, 'show'])
        ->middleware('ability:pipeline-jobs:read');
    Route::post('/pipeline-jobs', [PipelineJobController::class, 'store'])
        ->middleware('ability:pipeline-jobs:write');

    // Retrieval API — rate limited per workspace + budget enforced + ability
    Route::post('/retrieve', [RetrievalController::class, 'retrieve'])
        ->middleware(['throttle:api-retrieve', 'budget', 'ability:retrieve']);

    // Chat API — rate limited per workspace + budget enforced + ability
    Route::post('/chat', [ChatController::class, 'chat'])
        ->middleware(['throttle:api-chat', 'budget', 'ability:chat']);
});
