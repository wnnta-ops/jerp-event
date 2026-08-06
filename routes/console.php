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

// Lepas unit yang masa hold-nya habis (tak ada transfer cocok) → TERSEDIA.
Schedule::command('hold:release')->everyTenMinutes()->withoutOverlapping();

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
