<?php

use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\MonitoringArtifactDownloadController;
use App\Http\Controllers\Api\MonitoringEnrollmentController;
use App\Http\Controllers\Api\MonitoringHealthController;
use App\Http\Controllers\Api\SerproAdminController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::middleware('auth:sanctum')->get('/me', MeController::class);

Route::middleware('auth:sanctum')
    ->get('/monitoring/health', MonitoringHealthController::class)
    ->name('monitoring.health');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/monitoring/enrollments', [MonitoringEnrollmentController::class, 'index'])
        ->name('monitoring.enrollments.index');

    Route::post('/monitoring/enrollments', [MonitoringEnrollmentController::class, 'store'])
        ->name('monitoring.enrollments.store');

    Route::get('/monitoring/enrollments/{enrollment}', [MonitoringEnrollmentController::class, 'show'])
        ->name('monitoring.enrollments.show');

    Route::patch('/monitoring/enrollments/{enrollment}', [MonitoringEnrollmentController::class, 'update'])
        ->name('monitoring.enrollments.update');

    Route::delete('/monitoring/enrollments/{enrollment}', [MonitoringEnrollmentController::class, 'destroy'])
        ->name('monitoring.enrollments.destroy');

    Route::get('/admin/serpro', [SerproAdminController::class, 'show'])
        ->name('admin.serpro.show');

    Route::post('/admin/serpro/credentials', [SerproAdminController::class, 'storeCredentials'])
        ->name('admin.serpro.credentials');

    Route::post('/admin/serpro/environment', [SerproAdminController::class, 'switchEnvironment'])
        ->name('admin.serpro.environment');

    Route::post('/admin/serpro/transport', [SerproAdminController::class, 'setTransport'])
        ->name('admin.serpro.transport');
});

Route::middleware(['auth:sanctum', 'signed'])
    ->get('/monitoring/artifacts/{ref}/download', MonitoringArtifactDownloadController::class)
    ->where('ref', '[A-Za-z0-9_-]+')
    ->name('monitoring.artifacts.download');
