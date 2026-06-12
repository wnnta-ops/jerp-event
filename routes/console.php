<?php

use App\Models\BackupSchedule;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Backup terjadwal MULTI — loop semua BackupSchedule yang aktif. Tiap jadwal punya
 * frekuensi/jam/only_db sendiri (lokasi global ikut jadwal aktif pertama, lihat
 * AppServiceProvider). Spatie Backup mengompres jadi .zip. Dibaca ulang tiap
 * `schedule:run` sehingga perubahan jadwal langsung berlaku.
 */
try {
    $schedules = BackupSchedule::where('enabled', true)->get();

    foreach ($schedules as $s) {
        // `--only-db` flag tanpa nilai → dirangkai ke string command.
        $cmd = $s->only_db ? 'backup:run --only-db' : 'backup:run';

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
