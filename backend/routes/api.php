<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\InvitationController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\OnboardingController;
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
    Route::get('/invitations', [InvitationController::class, 'index']);
    Route::post('/invitations', [InvitationController::class, 'store']);
    Route::delete('/invitations/{id}', [InvitationController::class, 'destroy']);
});
Route::post('/invitations/{token}/accept', [InvitationController::class, 'accept']);
