<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Pengaturan jadwal & lokasi backup database (modul Backup).
 * Dipakai oleh scheduler (routes/console.php) dan override config Spatie Backup
 * di runtime (AppServiceProvider).
 */
class BackupSettings extends Settings
{
    public bool $enabled;

    /** WEEKLY | MONTHLY | YEARLY */
    public string $frequency;

    /** Jam jalan backup, format "HH:MM" (zona waktu APP_TIMEZONE). */
    public string $time;

    /** Mingguan: hari (0=Minggu … 6=Sabtu). */
    public int $weekly_day;

    /** Bulanan: tanggal (1–28). */
    public int $monthly_day;

    /** Tahunan: bulan (1–12) + tanggal (1–28). */
    public int $yearly_month;

    public int $yearly_day;

    /** Hanya backup database (true) atau database + storage (false). */
    public bool $only_db;

    /** Disk filesystem tujuan backup (mis. backup_target / local / s3). */
    public string $disk;

    /** Root folder untuk disk lokal kustom (backup_target). */
    public string $local_path;

    /** Simpan semua backup selama N hari sebelum di-thinning. */
    public int $retention_days;

    public static function group(): string
    {
        return 'backup';
    }
}
