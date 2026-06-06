<?php

use App\Settings\BackupSettings;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Backup terjadwal — frekuensi (mingguan/bulanan/tahunan), jam, dan lokasi
 * diambil dari BackupSettings (Pengaturan Backup). Spatie Backup otomatis
 * mengompres jadi .zip. Closure ->when() re-cek saat schedule:run berjalan.
 */
try {
    $backup = app(BackupSettings::class);

    // Flag dirangkai ke string command — `--only-db` adalah flag tanpa nilai,
    // sehingga tidak boleh dilewatkan sebagai ['--only-db' => true] (jadi --only-db='1').
    $runCmd = $backup->only_db ? 'backup:run --only-db' : 'backup:run';

    Schedule::command($runCmd)
        ->weeklyOn($backup->weekly_day, $backup->time)
        ->when(fn () => $backup->enabled && $backup->frequency === 'WEEKLY')
        ->withoutOverlapping()
        ->runInBackground();

    Schedule::command($runCmd)
        ->monthlyOn($backup->monthly_day, $backup->time)
        ->when(fn () => $backup->enabled && $backup->frequency === 'MONTHLY')
        ->withoutOverlapping()
        ->runInBackground();

    Schedule::command($runCmd)
        ->yearlyOn($backup->yearly_month, $backup->yearly_day, $backup->time)
        ->when(fn () => $backup->enabled && $backup->frequency === 'YEARLY')
        ->withoutOverlapping()
        ->runInBackground();

    // Bersihkan backup lama sesuai retensi, tiap hari.
    Schedule::command('backup:clean')
        ->dailyAt('03:30')
        ->when(fn () => $backup->enabled)
        ->withoutOverlapping();
} catch (Throwable) {
    // BackupSettings belum tersedia (mis. sebelum migrate) — lewati penjadwalan.
}
