<?php

use App\Models\BackupSchedule;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Collection: penagihan otomatis harian — channel DIKONTROL toggle Email/WA di
 * Setup Termin SP; KEDUANYA DEFAULT MATI = tidak ada kiriman otomatis sama
 * sekali (insiden email massal — nyalakan sadar bila ingin otomatis). Jam kirim
 * ikut setting "Jam kirim otomatis" (dibaca ulang tiap schedule:run → perubahan
 * dari Setup langsung berlaku). Try/catch: settings belum ada sebelum migrate.
 */
try {
    $jamKirim = substr((string) (app(\App\Settings\CollectionSettings::class)->auto_kirim_jam ?: '08:00'), 0, 5);
} catch (Throwable) {
    $jamKirim = '08:00';
}
Schedule::command('collection:run')->dailyAt($jamKirim)->withoutOverlapping();

// Modul Event: tarik Aktivasi NUP (FO) ke peserta event yang sedang dibuka, tiap 10 detik.
// Tanpa event berstatus open, perintah ini hanya satu query kosong.
Schedule::command('event:sinkron')->everyTenSeconds()->withoutOverlapping(1);

// Lepas unit yang masa hold-nya habis (tak ada transfer cocok) → TERSEDIA.
Schedule::command('hold:release')->everyTenMinutes()->withoutOverlapping();

// Notifikasi push app approver (PWA): cek BO Approval Inbox tiap menit — lihat PushApprover.
Schedule::command('pwa:push')->everyMinute()->withoutOverlapping()->runInBackground();

/*
 * Backup terjadwal MULTI — loop semua BackupSchedule yang aktif. Tiap jadwal punya
 * frekuensi/jam/only_db sendiri (lokasi global ikut jadwal aktif pertama, lihat
 * AppServiceProvider). Spatie Backup mengompres jadi .zip. Dibaca ulang tiap
 * `schedule:run` sehingga perubahan jadwal langsung berlaku.
 */
try {
    $schedules = BackupSchedule::where('enabled', true)->get();

    foreach ($schedules as $s) {
        // Cakupan backup: FULL|DB_ALL|DB_FO|DB_BO|APP. Fallback ke only_db lama bila kosong.
        $scope = $s->scope ?: ($s->only_db ? 'DB_ALL' : 'FULL');
        $cmd = 'backup:scoped '.$scope;

        $event = Schedule::command($cmd);
        match ($s->frequency) {
            'DAILY' => $event->dailyAt($s->time),
            'WEEKLY' => $event->weeklyOn($s->weekly_day, $s->time),
            'MONTHLY' => $event->monthlyOn($s->monthly_day, $s->time),
            'YEARLY' => $event->yearlyOn($s->yearly_month, $s->yearly_day, $s->time),
            default => $event->weeklyOn(1, $s->time),
        };
        $event->withoutOverlapping()->runInBackground();
    }

    // Bersihkan backup lama sesuai retensi (config global) tiap hari.
    if ($schedules->isNotEmpty()) {
        Schedule::command('backup:clean')->dailyAt('03:30')->withoutOverlapping();
    }
} catch (Throwable) {
    // Tabel backup_schedules belum ada (sebelum migrate) — lewati penjadwalan.
}

/*
 * Rekonsiliasi master Lokasi FO → cluster BO, tiap jam.
 *
 * Sinkron utamanya berbasis peristiwa (Lokasi::saved/deleted/restored di BoServiceProvider),
 * jadi biasanya tidak ada yang tersisa untuk dikerjakan di sini. Yang dijaga adalah keadaan
 * yang tak terpantau: antrean mati, IPRO_SYNC_ENABLED sempat dimatikan, atau job gagal sampai
 * kehabisan retry. Tanpa jaring ini, operator menambah cluster di FO, tidak melihatnya di BO,
 * lalu menyimpulkan BO-nya rusak — padahal cukup satu sinkron yang terlewat.
 *
 * Biayanya beberapa puluh SELECT dan ia idempoten, jadi aman berjalan sesering ini.
 */
if (config('ipro.sync_enabled', true)) {
    Schedule::command('ipro:backfill lokasi')->hourly()->withoutOverlapping()->runInBackground();
}
