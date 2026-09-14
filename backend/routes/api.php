<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AuditController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\InvitationController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\MonitoringActionController;
use App\Http\Controllers\Api\ArtifactDownloadController;
use App\Http\Controllers\Api\MonitoringEnrollmentController;
use App\Http\Controllers\Api\MonitoringReadController;
use App\Http\Controllers\Api\ParcelmentController;
use App\Http\Controllers\Api\OnboardingController;
use App\Http\Controllers\Api\PlanController;
use App\Http\Controllers\Api\SerproAdminController;
use App\Http\Controllers\Api\SwitchController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::middleware('auth:sanctum')->get('/me', MeController::class);

Route::get('/onboarding/status', [OnboardingController::class, 'status']);
Route::post('/onboarding', [OnboardingController::class, 'store']);

Route::middleware('auth:sanctum')->apiResource('accounts', AccountController::class)->only(['index', 'store']);

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/plans', [PlanController::class, 'index']);
    Route::post('/plans', [PlanController::class, 'store']);
    Route::patch('/plans/{plan}', [PlanController::class, 'update']);
    Route::patch('/accounts/{account}/plan', [AccountController::class, 'updatePlan']);

    Route::get('/clients', [ClientController::class, 'index']);
    Route::post('/clients', [ClientController::class, 'store']);
    Route::get('/clients/{id}', [ClientController::class, 'show']);
    Route::patch('/clients/{id}', [ClientController::class, 'update']);
    Route::delete('/clients/{id}', [ClientController::class, 'destroy']);
    Route::patch('/clients/{id}/monitoring', [ClientController::class, 'updateMonitoring']);
});

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/invitations', [InvitationController::class, 'index']);
    Route::post('/invitations', [InvitationController::class, 'store']);
    Route::delete('/invitations/{id}', [InvitationController::class, 'destroy']);
});
Route::post('/invitations/{token}/accept', [InvitationController::class, 'accept']);

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/switch', [SwitchController::class, 'store']);
    Route::delete('/switch', [SwitchController::class, 'destroy']);
    Route::get('/audit', [AuditController::class, 'index']);

    Route::get('/admin/serpro', [SerproAdminController::class, 'show']);
    Route::put('/admin/serpro/credentials', [SerproAdminController::class, 'updateCredentials']);
    Route::post('/admin/serpro/environment', [SerproAdminController::class, 'switchEnvironment']);
    Route::post('/admin/serpro/transport', [SerproAdminController::class, 'switchTransport']);

    Route::get('/monitoring/enrollments', [MonitoringEnrollmentController::class, 'index']);
    Route::post('/monitoring/enrollments', [MonitoringEnrollmentController::class, 'store']);
    Route::get('/monitoring/divergences', [MonitoringEnrollmentController::class, 'divergences']);
    Route::get('/monitoring/enrollments/{id}', [MonitoringEnrollmentController::class, 'show'])->whereNumber('id');
    Route::post('/monitoring/enrollments/{id}/pause', [MonitoringEnrollmentController::class, 'pause'])->whereNumber('id');
    Route::post('/monitoring/enrollments/{id}/resume', [MonitoringEnrollmentController::class, 'resume'])->whereNumber('id');
    Route::delete('/monitoring/enrollments/{id}', [MonitoringEnrollmentController::class, 'destroy'])->whereNumber('id');

    Route::get('/monitoring/dashboard', [MonitoringReadController::class, 'dashboard']);
    Route::get('/monitoring/clients/{client}/snapshots', [MonitoringReadController::class, 'snapshots'])->whereNumber('client');
    Route::get('/monitoring/clients/{client}/changes', [MonitoringReadController::class, 'changes'])->whereNumber('client');
    Route::get('/monitoring/clients/{client}/alerts', [MonitoringReadController::class, 'alerts'])->whereNumber('client');
    Route::post('/monitoring/clients/{client}/alerts/{alert}/acknowledge', [MonitoringReadController::class, 'acknowledge'])->whereNumber(['client', 'alert']);
    Route::get('/monitoring/clients/{client}/cnd', [MonitoringReadController::class, 'cnd'])->whereNumber('client');

    Route::get('/monitoring/clients/{client}/parcelamentos/{modality}', [ParcelmentController::class, 'index'])->whereNumber('client');
    Route::get('/monitoring/clients/{client}/parcelamentos/orders/{order}', [ParcelmentController::class, 'show'])->whereNumber(['client', 'order']);
    Route::get('/monitoring/clients/{client}/parcelamentos/orders/{order}/guia', [ParcelmentController::class, 'guia'])->whereNumber(['client', 'order']);

    Route::post('/monitoring/actions/emissoes', [MonitoringActionController::class, 'store']);
    Route::post('/monitoring/actions/requests/{id}/poll', [MonitoringActionController::class, 'poll'])->whereNumber('id');

    Route::get('/monitoring/artifacts/{ref}/url', [ArtifactDownloadController::class, 'url']);
    Route::get('/monitoring/artifacts/{ref}/download', [ArtifactDownloadController::class, 'download'])
        ->name('monitoring.artifacts.download')->middleware('signed');
});
