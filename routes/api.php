<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\Mobile\ApprovalController;
use App\Http\Controllers\Api\Mobile\BoInboxController;
use App\Http\Controllers\Api\Mobile\PushController;
use App\Http\Controllers\Api\Mobile\SesiController;
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
        Route::get('/auth/sessions', [SesiController::class, 'index']);
        Route::delete('/auth/sessions/{id}', [SesiController::class, 'destroy'])->whereNumber('id');
        Route::post('/auth/logout-all', [SesiController::class, 'destroyAll']);

        Route::get('/approvals', [ApprovalController::class, 'queue']);
        Route::get('/approvals/history', [ApprovalController::class, 'history']);
        Route::post('/approvals/decisions', [ApprovalController::class, 'decideMany']);
        Route::get('/approvals/signature', [ApprovalController::class, 'signature']);
        Route::get('/approvals/{approval}', [ApprovalController::class, 'show']);
        Route::get('/approvals/{approval}/docs', [ApprovalController::class, 'docs']);
        Route::post('/approvals/{approval}/decision', [ApprovalController::class, 'decide']);
        Route::post('/approvals/{approval}/review', [ApprovalController::class, 'review']);

        // BO Approval Inbox (PWA) — diteruskan ke BO, lihat BoInboxBridge.
        Route::get('/inbox', [BoInboxController::class, 'index']);
        Route::get('/inbox/history', [BoInboxController::class, 'history']);
        Route::get('/inbox/profil', [BoInboxController::class, 'profil']);
        Route::get('/inbox/{id}', [BoInboxController::class, 'show'])->whereNumber('id');
        Route::post('/inbox/{id}/act', [BoInboxController::class, 'act'])->whereNumber('id');
        Route::get('/inbox/{id}/dokumen/{kunci}', [BoInboxController::class, 'dokumen'])
            ->whereNumber('id')->where('kunci', 'memo|lampiran|file-[0-9]+|(alokasi|koreksi)-[0-9]+');
        Route::get('/dashboard', [BoInboxController::class, 'dashboard']);

        // Notifikasi push (PWA) — dikirim job pwa:push tiap menit.
        Route::get('/push/config', [PushController::class, 'config']);
        Route::post('/push/status', [PushController::class, 'status']);
        Route::post('/push/subscribe', [PushController::class, 'subscribe']);
        Route::put('/push/subscription', [PushController::class, 'update']);
        Route::post('/push/unsubscribe', [PushController::class, 'unsubscribe']);
        Route::post('/push/uji', [PushController::class, 'uji'])->middleware('throttle:6,1');
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
