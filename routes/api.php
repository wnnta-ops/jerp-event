<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\Mobile\ApprovalController;
use App\Http\Controllers\Api\SurveiSprController;
use App\Modules\BO\IproBridge\Controllers\LegalBridgeController;
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

/*
 * Jembatan modul Legal (app/legal, Next.js di compose yang sama) → FO & ipro_bo.
 * Tanpa sesi & tanpa CSRF: tiap permintaan ditandatangani HMAC dengan kunci
 * bersama (LegalBridgeSignature). Dipakai saat petugas Legal mengarsipkan akta
 * AJB dan menautkannya ke No. SPR (Final) di Marketing & Sales.
 */
Route::prefix('legal-bridge')->middleware('throttle:60,1')->group(function () {
    Route::post('/spr', [LegalBridgeController::class, 'spr'])->name('legal-bridge.spr');
    Route::post('/ajb', [LegalBridgeController::class, 'simpanAjb'])->name('legal-bridge.ajb');
    Route::post('/ajb/lepas', [LegalBridgeController::class, 'lepasAjb'])->name('legal-bridge.ajb.lepas');
});

/*
 * Aplikasi Survei — daftar SPR (No. SPR, nama customer, unit). Baca saja.
 * Auth: header "Authorization: Bearer <SURVEI_API_KEY>" (atau X-Api-Key).
 */
Route::get('/survei/spr', SurveiSprController::class)->middleware('throttle:60,1')->name('api.survei.spr');
