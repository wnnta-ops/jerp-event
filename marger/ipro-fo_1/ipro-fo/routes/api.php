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

    // ── Jembatan FO → Approval Inbox (app approver PWA), HMAC sso.secret ──
    Route::prefix('fo-bridge/approval-inbox')->name('fo-bridge.inbox.')
        // Throttle grup API (per IP) dilepas — batasnya per user di VerifyFoBridge.
        ->withoutMiddleware(Illuminate\Routing\Middleware\ThrottleRequests::class.':api')
        ->middleware([App\Http\Middleware\VerifyFoBridge::class, 'set-tenant', 'audit-context'])
        ->group(function (): void {
            $c = App\Http\Controllers\Api\V1\FoBridge\ApprovalInboxBridgeController::class;
            Route::get('/', [$c, 'index'])->name('index');
            Route::get('/history', [$c, 'history'])->name('history');
            Route::get('/profil', [$c, 'profil'])->name('profil');
            Route::get('/pengajuan', [$c, 'pengajuan'])->name('pengajuan');
            Route::get('/{approval}', [$c, 'show'])->whereNumber('approval')->name('show');
            Route::post('/{approval}/act', [$c, 'act'])->whereNumber('approval')->name('act');
            Route::get('/{approval}/dokumen/{kunci}', [$c, 'dokumen'])->whereNumber('approval')
                ->where('kunci', 'memo|lampiran|file-[0-9]+')->name('dokumen');
        });
    Route::get('fo-bridge/dashboard', App\Http\Controllers\Api\V1\FoBridge\DashboardBridgeController::class)
        // Throttle grup API (per IP) dilepas — batasnya per user di VerifyFoBridge.
        ->withoutMiddleware(Illuminate\Routing\Middleware\ThrottleRequests::class.':api')
        ->middleware([App\Http\Middleware\VerifyFoBridge::class, 'set-tenant', 'audit-context'])
        ->name('fo-bridge.dashboard');

    // ── Authenticated ─────────────────────────────────
    Route::middleware(['auth:sanctum', 'set-tenant', 'audit-context', 'idempotency'])
        ->group(function (): void {
            Route::get('auth/me',      [LoginController::class, 'me'])->name('auth.me');
            Route::post('auth/logout', [LoginController::class, 'logout'])->name('auth.logout');

            // Master
            Route::apiResource('customers', CustomerController::class);
            Route::apiResource('units',     UnitController::class)->only(['index', 'show']);

            // Sales — sudah PINDAH ke FO. Dimatikan lewat sakelar yang sama dengan
            // rutenya di web.php: store/cancel/promote masih bisa MENULIS ke data
            // booking yang kini dikelola FO, dan API tidak punya menu untuk dicabut.
            // Kembalikan dengan BO_MODUL_SALES_AKTIF=true di .env.
            if (config('modul_pindah_fo.sales')) {
                Route::apiResource('bookings', BookingController::class)->only(['index', 'store', 'show']);
                Route::post('bookings/{booking}/cancel',  [BookingController::class, 'cancel'])->name('bookings.cancel');
                Route::post('bookings/{booking}/promote', [BookingController::class, 'promote'])->name('bookings.promote');
            }

            // Finance — sudah PINDAH ke FO. Sama seperti bookings: API tidak punya
            // menu untuk dicabut, jadi store/post/giro masih bisa MENULIS diam-diam.
            if (config('modul_pindah_fo.finance')) {
                Route::apiResource('tts', TtsController::class)->only(['index', 'store', 'show'])->parameters(['tts' => 'tts']);
                Route::post('tts/{tts}/post',        [TtsController::class, 'post'])->name('tts.post');
                Route::post('tts/{tts}/giro/clear',  [TtsController::class, 'clearGiro'])->name('tts.giro.clear');
                Route::post('tts/{tts}/giro/reject', [TtsController::class, 'rejectGiro'])->name('tts.giro.reject');
            }

            // Reporting — sudah PINDAH ke FO.
            if (config('modul_pindah_fo.laporan')) {
                Route::get('dashboards/executive', [DashboardController::class, 'executive'])->name('dashboards.executive');
            }

            // Search (untuk Cmd+K)
            Route::get('search', [SearchController::class, 'index'])->name('search');
        });
});
