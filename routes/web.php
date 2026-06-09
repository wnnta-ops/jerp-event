<?php

use App\Http\Controllers\WilayahController;
use App\Livewire\Auth\Login;
use App\Livewire\Nup\PublicForm as NupPublicForm;
use App\Modules\BO\GeneralLedger\Controllers\JurnalPrintController;
use App\Modules\FO\Launching\Controllers\SiteplanController;
use App\Modules\FO\MarketingSales\Controllers\ClosingPrintController;
use App\Modules\FO\MarketingSales\Controllers\CustomerQrController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

// ─────────────────────────────────────────────────────────────────────────
// Subdomain publik OPSIONAL (env NUP_DOMAIN / SITEPLAN_DOMAIN). Didefinisikan
// SEBELUM route global "/" agar cocok lebih dulu saat host = subdomain tsb.
// Kosong di .env (lokal) → blok ini di-skip, akses tetap via path biasa.
// ─────────────────────────────────────────────────────────────────────────
if ($nupDomain = config('jerp.nup_domain')) {
    Route::domain($nupDomain)->middleware(['throttle:nup-public'])->group(function () {
        Route::get('/', NupPublicForm::class)->name('nup.daftar.subdomain');
    });
}

if ($siteplanDomain = config('jerp.siteplan_domain')) {
    Route::domain($siteplanDomain)->middleware(['throttle:120,1'])->group(function () {
        Route::get('/', [SiteplanController::class, 'index'])->name('siteplan.subdomain.index');
        Route::get('/{project}', [SiteplanController::class, 'show'])->name('siteplan.subdomain.show');
    });
}

Route::get('/', fn () => auth()->check() ? redirect()->route('dashboard') : redirect()->route('login'));

// Public NUP registration (BR-NUP, see docs/04_BusinessFlow.md §2) — NO auth, rate-limited.
Route::middleware(['throttle:nup-public'])
    ->group(function () {
        Route::get('/nup/daftar', NupPublicForm::class)->name('nup.daftar');
    });

// SPRS (Surat Pemesanan Rumah Sementara) — link dikirim ke customer via WA/email
// saat verifikasi data di-APPROVE. SIGNED URL agar tak bisa diakses/diutak-atik
// sembarangan (tanpa login). ?pdf=1 → unduh PDF (butuh Chromium/Browsershot).
Route::middleware(['throttle:120,1', 'signed'])
    ->get('/nup/sprs/{registration}', [ClosingPrintController::class, 'sprs'])
    ->name('fo.cetak.sprs');

// Public site plan viewer (marketing — customer & sales). Read-only.
Route::middleware(['throttle:120,1'])
    ->prefix('siteplan')->name('siteplan.')
    ->group(function () {
        Route::get('/', [SiteplanController::class, 'index'])->name('index');
        Route::get('/{project}', [SiteplanController::class, 'show'])->name('show');
    });

// Proxy data wilayah (hindari CORS emsifa.github.io). Same-origin, cached server-side.
// Throttle longgar terpisah: cascading dropdown bisa banyak request per pengisian form.
Route::middleware(['throttle:120,1'])
    ->prefix('wilayah')->name('wilayah.')
    ->group(function () {
        Route::get('/provinces', [WilayahController::class, 'provinces'])->name('provinces');
        Route::get('/regencies/{provinceId}', [WilayahController::class, 'regencies'])->name('regencies')->whereNumber('provinceId');
        Route::get('/districts/{regencyId}', [WilayahController::class, 'districts'])->name('districts')->whereNumber('regencyId');
        Route::get('/villages/{districtId}', [WilayahController::class, 'villages'])->name('villages')->whereNumber('districtId');
    });

Route::middleware('guest')->group(function () {
    Route::get('/login', Login::class)->name('login');
});

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', fn () => view('dashboard'))->name('dashboard');

    // BO — cetak voucher jurnal (HTML printable; ?pdf=1 untuk Browsershot di produksi)
    Route::get('/bo/cetak/jurnal/{jurnal}', [JurnalPrintController::class, 'show'])
        ->name('bo.cetak.jurnal');

    // FO — kartu QR customer (printable; di-scan saat Pilih Unit hari-H launching)
    Route::get('/fo/customer/{customer}/qr', [CustomerQrController::class, 'show'])
        ->name('fo.customer.qr');

    // FO — cetak dokumen closing (SPR, Kwitansi/BKM, Jadwal Pembayaran). ?pdf=1 → Browsershot.
    Route::get('/fo/cetak/spr/{kontrak}', [ClosingPrintController::class, 'spr'])->name('fo.cetak.spr');
    Route::get('/fo/cetak/kwitansi/{bkm}', [ClosingPrintController::class, 'kwitansi'])->name('fo.cetak.kwitansi');
    Route::get('/fo/cetak/jadwal/{kontrak}', [ClosingPrintController::class, 'jadwal'])->name('fo.cetak.jadwal');

    Route::post('/logout', function () {
        Auth::logout();
        request()->session()->invalidate();
        request()->session()->regenerateToken();

        return redirect()->route('login');
    })->name('logout');
});
