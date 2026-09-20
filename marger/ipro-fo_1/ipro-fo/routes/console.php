<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    /** @var Illuminate\Console\Command $this */
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ── Scheduled jobs (Asia/Jakarta) ────────────────────────────────────────────
//
// KELIMA PERINTAH HARIAN DI BAWAH TIDAK PERNAH BERJALAN. Kontainer ipro-fo-scheduler tidak
// dinyalakan oleh deploy.sh/up.sh/up-bo.sh — hanya app, nginx, dan redis. Jadi sejak
// dijadwalkan, tidak satu pun pernah dieksekusi.
//
// Karena penjadwal kini DINYALAKAN (untuk penarikan MUK & Komplain), kelimanya sengaja
// digerbangi supaya tidak ikut hidup diam-diam. Menyalakannya serentak berarti memproses
// tumpukan berbulan-bulan sekaligus — dan salah satunya menyentuh uang: pada basis data
// pengembangan, billing:jatuh-tempo akan menandai 60 jadwal pembayaran jatuh tempo SEKALIGUS
// menghitung dendanya. Itu keputusan Finance, bukan efek samping menyalakan penjadwal.
//
// Untuk menghidupkannya: periksa dulu berapa baris yang akan tersentuh di server, lalu set
// BO_JADWAL_HARIAN_AKTIF=true di .env, lalu php artisan config:clear.
$jadwalHarianAktif = fn () => (bool) config('services.jadwal_harian_bo');

Schedule::command('hold:expire')->dailyAt('00:30')->onOneServer()->when($jadwalHarianAktif);
Schedule::command('booking:expire')->dailyAt('00:40')->onOneServer()->when($jadwalHarianAktif);
Schedule::command('billing:jatuh-tempo')->dailyAt('01:00')->onOneServer()->when($jadwalHarianAktif);
Schedule::command('pjt:generate')->dailyAt('02:00')->onOneServer()->when($jadwalHarianAktif);
Schedule::command('tunggakan:update')->dailyAt('02:30')->onOneServer()->when($jadwalHarianAktif);

// Kontrak MUK ditarik tiap menit. Penarikannya BERTAHAP (?diperbarui_sejak=), jadi bila tidak
// ada perubahan ia hanya menanyakan "ada yang berubah?" dan pulang dengan daftar kosong —
// satu permintaan, di bawah 1% dari jatah 120/menit milik MUK.
//
// withoutOverlapping: bila MUK sedang lambat, penarikan menit berikutnya TIDAK menumpuk di
// atasnya. Tanpa ini, satu panggilan yang menggantung 20 detik akan beranak tiap menit.
// runInBackground: penjadwal tidak ikut tertahan menunggu server orang lain.
//
// Kegagalan tidak berteriak ke mana pun — yang terlihat adalah tulisan "Ditarik <waktu>" di
// layar Kontrak SPK (MUK) yang berhenti maju. Itu disengaja: memuntahkan galat tiap menit
// saat MUK sedang mati hanya memenuhi log tanpa memberi tahu siapa pun.
Schedule::command('muk:tarik-kontrak')->everyMinute()->onOneServer()
    ->withoutOverlapping(5)->runInBackground();

// Data BAST dari sistem Komplain. Lebih jarang daripada MUK karena serah terima rumah
// terjadi harian, bukan menitan — lima menit sudah jauh lebih segar daripada kebutuhannya.
// Bertahap lewat updated_since, jadi bila tidak ada perubahan ia hanya bertanya lalu pulang.
Schedule::command('cm:tarik-unit')->everyFiveMinutes()->onOneServer()
    ->withoutOverlapping(10)->runInBackground();
