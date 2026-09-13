<?php

use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\MonitoringArtifactDownloadController;
use App\Http\Controllers\Api\MonitoringHealthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::middleware('auth:sanctum')->get('/me', MeController::class);

Route::middleware('auth:sanctum')
    ->get('/monitoring/health', MonitoringHealthController::class)
    ->name('monitoring.health');

Route::middleware(['auth:sanctum', 'signed'])
    ->get('/monitoring/artifacts/{ref}/download', MonitoringArtifactDownloadController::class)
    ->where('ref', '[A-Za-z0-9_-]+')
    ->name('monitoring.artifacts.download');
