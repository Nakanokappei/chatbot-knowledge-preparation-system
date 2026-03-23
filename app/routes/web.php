<?php

use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
Route::get('/jobs/{pipelineJob}', [DashboardController::class, 'show'])->name('dashboard.show');
Route::post('/dispatch', [DashboardController::class, 'dispatch'])->name('dashboard.dispatch');
Route::post('/dispatch-pipeline', [DashboardController::class, 'dispatchPipeline'])->name('dashboard.dispatch-pipeline');
