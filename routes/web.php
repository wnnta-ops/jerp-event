<?php

use App\Http\Controllers\WilayahController;
use App\Livewire\Auth\Login;
use App\Modules\BO\IproBridge\Controllers\SsoController;
use App\Livewire\Booked\PublicForm as BookedPublicForm;
use App\Livewire\Nup\PublicForm as NupPublicForm;
use App\Modules\BO\GeneralLedger\Controllers\JurnalPrintController;
use App\Modules\FO\Launching\Controllers\SiteplanController;
use App\Modules\FO\MarketingSales\Controllers\ClosingPrintController;
use App\Modules\FO\MarketingSales\Controllers\CustomerFileController;
use App\Modules\FO\MarketingSales\Controllers\CustomerQrController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

// SSO token-handoff → buka BO/Bankloan ipro-fo dgn sesi login J-ERP.
Route::get('/sso/to-bo', [SsoController::class, 'toBo'])
    ->middleware(['web', 'auth'])
    ->name('sso.to-bo');

// SSO token-handoff → app portal Legal & Sales (Next.js /legal & /sales).
Route::get('/sso/to-legal', [SsoController::class, 'toLegal'])
    ->middleware(['web', 'auth'])
    ->name('sso.to-legal');
Route::get('/sso/to-sales', [SsoController::class, 'toSales'])
    ->middleware(['web', 'auth'])
    ->name('sso.to-sales');

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

// Siteplan LIVE (acara NUP) punya subdomain sendiri (env SITEPLANLIVE_DOMAIN).
// Root "/" subdomain → langsung halaman live. Real-time butuh proxy WebSocket
// Reverb di subdomain ini juga (wss://<subdomain>/app → reverb).
if ($siteplanLiveDomain = config('jerp.siteplanlive_domain')) {
    Route::domain($siteplanLiveDomain)->middleware(['throttle:120,1'])->group(function () {
        Route::get('/', [SiteplanController::class, 'live'])->name('siteplan.live.subdomain');
    });
}

if ($bookedDomain = config('jerp.booked_domain')) {
    Route::domain($bookedDomain)->middleware(['throttle:nup-public'])->group(function () {
        Route::get('/', BookedPublicForm::class)->name('booked.daftar.subdomain');
    });
}

Route::get('/', fn () => auth()->check() ? redirect()->route('dashboard') : redirect()->route('login'));

// Public NUP registration (BR-NUP, see docs/04_BusinessFlow.md §2) — NO auth, rate-limited.
Route::middleware(['throttle:nup-public'])
    ->group(function () {
        Route::get('/nup/daftar', NupPublicForm::class)->name('nup.daftar');
        // Booked Manual — pendaftaran booking di luar launching (transfer bank manual).
        Route::get('/booked/daftar', BookedPublicForm::class)->name('booked.daftar');
    });

// SPRS (Surat Pemesanan Rumah Sementara) — link dikirim ke customer via WA/email
// saat verifikasi data di-APPROVE. SIGNED URL agar tak bisa diakses/diutak-atik
// sembarangan (tanpa login). ?pdf=1 → unduh PDF (butuh Chromium/Browsershot).
Route::middleware(['throttle:120,1', 'signed'])
    ->get('/nup/sprs/{registration}', [ClosingPrintController::class, 'sprs'])
    ->name('fo.cetak.sprs');

// PDF SPRS TANPA query string (token HMAC di path) — utk header dokumen WA:
// Qontak/Meta menolak URL dokumen yang mengandung '?','&','=' (signed URL gagal 422).
Route::middleware(['throttle:120,1'])
    ->get('/nup/sprs/{registration}/{hash}.pdf', [ClosingPrintController::class, 'sprsPdf'])
    ->where('hash', '[0-9a-f]{64}')
    ->name('fo.cetak.sprs-pdf');

// KWITANSI UTJ — dikirim ke customer via WA & email setelah pembayaran Xendit
// berhasil. Pola sama dengan SPRS: signed URL untuk dilihat/diunduh customer,
// dan varian path-token tanpa query string untuk header dokumen WA.
Route::middleware(['throttle:120,1', 'signed'])
    ->get('/nup/kwitansi/{registration}', [ClosingPrintController::class, 'kwitansiUtj'])
    ->name('fo.cetak.kwitansi-utj');

Route::middleware(['throttle:120,1'])
    ->get('/nup/kwitansi/{registration}/{hash}.pdf', [ClosingPrintController::class, 'kwitansiUtjPdf'])
    ->where('hash', '[0-9a-f]{64}')
    ->name('fo.cetak.kwitansi-utj-pdf');

// Public site plan viewer (marketing — customer & sales). Read-only.
Route::middleware(['throttle:120,1'])
    ->prefix('siteplan')->name('siteplan.')
    ->group(function () {
        Route::get('/', [SiteplanController::class, 'index'])->name('index');
        Route::get('/{project}', [SiteplanController::class, 'show'])->name('show');
    });

// Siteplan LIVE (acara NUP) — real-time stock + animasi SOLD OUT. Setup di Pengaturan FO.
Route::middleware(['throttle:120,1'])
    ->get('/siteplanlive', [SiteplanController::class, 'live'])->name('siteplan.live');

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

    // FO — Ganti/Hapus gambar Site Plan cluster via upload HTML biasa (bypass FilePond/Livewire).
    Route::post('/fo/site-plan/image', [\App\Modules\FO\MarketingSales\Controllers\SitePlanImageController::class, 'upload'])
        ->name('fo.siteplan.image.upload');
    Route::delete('/fo/site-plan/image', [\App\Modules\FO\MarketingSales\Controllers\SitePlanImageController::class, 'destroy'])
        ->name('fo.siteplan.image.destroy');

    // FO — kartu QR customer (printable; di-scan saat Pilih Unit hari-H launching)
    Route::get('/fo/customer/{customer}/qr', [CustomerQrController::class, 'show'])
        ->name('fo.customer.qr');

    // FO — berkas private customer (KTP/NPWP) untuk tim verifikasi data.
    Route::get('/fo/customer/{customer}/file/{type}', [CustomerFileController::class, 'show'])
        ->name('fo.customer.file');
    Route::get('/fo/customer/{customer}/files-zip', [CustomerFileController::class, 'zip'])
        ->name('fo.customer.files-zip');

    // Booked Manual — bukti transfer (untuk tim verifikasi).
    Route::get('/fo/booked/{registration}/bukti-transfer', [CustomerFileController::class, 'bookedBukti'])
        ->name('fo.booked.bukti');

    // FO — cetak dokumen closing (SPR, Kwitansi/BKM, Jadwal Pembayaran). ?pdf=1 → Browsershot.
    Route::get('/fo/cetak/spr/{kontrak}', [ClosingPrintController::class, 'spr'])->name('fo.cetak.spr');
    Route::get('/fo/cetak/spr-accounting/{kontrak}', [ClosingPrintController::class, 'sprAccounting'])->name('fo.cetak.spr-accounting');
    Route::get('/fo/cetak/sprs-kontrak/{kontrak}', [ClosingPrintController::class, 'sprsFromKontrak'])->name('fo.cetak.sprs-kontrak');
    Route::get('/fo/cetak/kwitansi/{bkm}', [ClosingPrintController::class, 'kwitansi'])->name('fo.cetak.kwitansi');
    Route::get('/fo/cetak/jadwal/{kontrak}', [ClosingPrintController::class, 'jadwal'])->name('fo.cetak.jadwal');
    Route::get('/fo/cetak/sp/{sp}', [ClosingPrintController::class, 'sp'])->name('fo.cetak.sp');
    Route::get('/fo/cetak/faktur/{fp}', [ClosingPrintController::class, 'fakturPenjualan'])->name('fo.cetak.faktur');

    // FO — Laporan Penjualan (Penjualan Netto): export Excel, filter cluster/periode dari halaman Filament.
    Route::get('/fo/laporan/penjualan/excel', [\App\Modules\FO\MarketingSales\Controllers\LaporanPenjualanController::class, 'excel'])->name('fo.laporan.penjualan.excel');

    // FO — Report Xendit (Finance AR): export Excel pencocokan pembayaran UTJ <-> customer.
    Route::get('/fo/report/xendit/excel', [\App\Modules\FO\Launching\Controllers\ReportXenditController::class, 'excel'])->name('fo.report.xendit.excel');

    // FO — cetak Permohonan Pengajuan BLT (1 dokumen, 3 halaman: Sales/Koordinator/Kantor).
    Route::get('/fo/cetak/pengajuan-blt', [\App\Modules\FO\Komisi\Controllers\BltPrintController::class, 'pengajuan'])->name('fo.cetak.pengajuan-blt');
    Route::get('/fo/cetak/pengajuan-komisi', [\App\Modules\FO\Komisi\Controllers\KomisiPrintController::class, 'pengajuan'])->name('fo.cetak.pengajuan-komisi');

    Route::post('/logout', function () {
        Auth::logout();
        request()->session()->invalidate();
        request()->session()->regenerateToken();

        return redirect()->route('login');
    })->name('logout');
});
