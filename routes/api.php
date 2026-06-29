<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\Mobile\ApprovalController;
use App\Modules\FO\Launching\Controllers\XenditWebhookController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// Xendit invoice webhook (UTJ NUP) — token-verified, no auth/session/CSRF.
Route::post('/webhooks/xendit', XenditWebhookController::class)->name('webhooks.xendit');

/*
 * Mobile Approver API — dipakai aplikasi Expo/React Native (folder /mobile).
 * Auth via Sanctum personal access token (login → Bearer token).
 */
Route::prefix('mobile')->middleware('throttle:api')->group(function () {
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:mobile-login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);

        Route::get('/approvals', [ApprovalController::class, 'queue']);
        Route::get('/approvals/history', [ApprovalController::class, 'history']);
        Route::post('/approvals/decisions', [ApprovalController::class, 'decideMany']);
        Route::get('/approvals/signature', [ApprovalController::class, 'signature']);
        Route::get('/approvals/{approval}', [ApprovalController::class, 'show']);
        Route::get('/approvals/{approval}/docs', [ApprovalController::class, 'docs']);
        Route::post('/approvals/{approval}/decision', [ApprovalController::class, 'decide']);
        Route::post('/approvals/{approval}/review', [ApprovalController::class, 'review']);
    });
});
