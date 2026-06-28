<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Finance\TtsController;
use App\Http\Controllers\Api\V1\Master\CustomerController;
use App\Http\Controllers\Api\V1\Reporting\DashboardController;
use App\Http\Controllers\Api\V1\Sales\BookingController;
use App\Http\Controllers\Api\V1\Sales\UnitController;
use App\Http\Controllers\Api\V1\SearchController;
use App\Http\Controllers\Api\V1\Webhook\BankVaController;
use Illuminate\Support\Facades\Route;

// Semua route API di-namespace dengan prefix nama `api.` agar tidak bentrok
// dengan web routes yang punya nama sama (mis. `customers.index`).
Route::prefix('v1')->name('api.')->group(function (): void {
    // ── Public ──────────────────────────────────────────
    Route::post('auth/login', [LoginController::class, 'login'])
        ->middleware('throttle:10,1')
        ->name('auth.login');

    // ── Webhooks (HMAC verified) ──────────────────────
    Route::prefix('webhooks')->name('webhooks.')->middleware('verify-hmac')->group(function (): void {
        Route::post('bank/va', [BankVaController::class, 'handle'])->name('bank.va');
    });

    // ── Authenticated ─────────────────────────────────
    Route::middleware(['auth:sanctum', 'set-tenant', 'audit-context', 'idempotency'])
        ->group(function (): void {
            Route::get('auth/me',      [LoginController::class, 'me'])->name('auth.me');
            Route::post('auth/logout', [LoginController::class, 'logout'])->name('auth.logout');

            // Master
            Route::apiResource('customers', CustomerController::class);
            Route::apiResource('units',     UnitController::class)->only(['index', 'show']);

            // Sales
            Route::apiResource('bookings', BookingController::class)->only(['index', 'store', 'show']);
            Route::post('bookings/{booking}/cancel',  [BookingController::class, 'cancel'])->name('bookings.cancel');
            Route::post('bookings/{booking}/promote', [BookingController::class, 'promote'])->name('bookings.promote');

            // Finance
            Route::apiResource('tts', TtsController::class)->only(['index', 'store', 'show'])->parameters(['tts' => 'tts']);
            Route::post('tts/{tts}/post',        [TtsController::class, 'post'])->name('tts.post');
            Route::post('tts/{tts}/giro/clear',  [TtsController::class, 'clearGiro'])->name('tts.giro.clear');
            Route::post('tts/{tts}/giro/reject', [TtsController::class, 'rejectGiro'])->name('tts.giro.reject');

            // Reporting
            Route::get('dashboards/executive', [DashboardController::class, 'executive'])->name('dashboards.executive');

            // Search (untuk Cmd+K)
            Route::get('search', [SearchController::class, 'index'])->name('search');
        });
});
