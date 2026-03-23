<?php

use App\Http\Controllers\Api\DatasetController;
use App\Http\Controllers\Api\PipelineJobController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| All routes require Sanctum authentication and tenant association.
| Routes are prefixed with /api automatically by Laravel.
|
*/

// Authenticated user info
Route::get('/user', function (Request $request) {
    return $request->user()->load('tenant');
})->middleware('auth:sanctum');

// Protected API routes requiring authentication
Route::middleware('auth:sanctum')->group(function () {

    // Dataset management
    Route::apiResource('datasets', DatasetController::class)->only([
        'index', 'show', 'store',
    ]);

    // Pipeline job management
    Route::apiResource('pipeline-jobs', PipelineJobController::class)->only([
        'index', 'show', 'store',
    ]);
});
