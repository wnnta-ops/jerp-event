<?php

namespace App\Console\Commands\Backup;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * Restore database dari file backup (zip Spatie laravel-backup).
 *
 * MENIMPA seluruh data DB saat ini dengan isi backup. Dipakai untuk alur
 * "gladi kotor": Buat Backup → gladi → backup:restore (DB balik ke titik backup).
 * Default membuat backup darurat (DB-only) lebih dulu sebagai jaring pengaman.
 */
class RestoreBackupCommand extends Command
{
    protected $signature = 'backup:restore
        {path : Path relatif file .zip backup pada disk}
        {--disk= : Disk backup (default dari config backup)}
        {--force : Lewati konfirmasi interaktif}
        {--no-safety-backup : Lewati backup darurat sebelum restore}';

    protected $description = 'Restore database dari file backup zip (MENIMPA data saat ini).';

    public function handle(): int
    {
        $disk = $this->option('disk') ?: (config('backup.backup.destination.disks')[0] ?? 'local');
        $path = $this->argument('path');
        $storage = Storage::disk($disk);
        $dbName = (string) config('database.connections.mysql.database');

        if (! $storage->exists($path)) {
            $this->error("File backup tidak ditemukan: [{$disk}] {$path}");

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm("Restore akan MENIMPA seluruh database '{$dbName}' dari {$path}. Lanjutkan?")) {
            $this->warn('Dibatalkan.');

            return self::FAILURE;
        }

        // Jaring pengaman: backup darurat (DB-only) sebelum menimpa.
        if (! $this->option('no-safety-backup')) {
            $this->info('Membuat backup darurat (DB-only) lebih dulu…');
            try {
                Artisan::call('backup:run', ['--only-db' => true]);
            } catch (\Throwable $e) {
                $this->error('Backup darurat gagal — restore dibatalkan demi keamanan: '.$e->getMessage());

                return self::FAILURE;
            }
        }

        // Salin zip ke temp lokal (mendukung disk apa pun), lalu ekstrak db-dump .sql.
        $tmpZip = tempnam(sys_get_temp_dir(), 'restore-zip-');
        file_put_contents($tmpZip, $storage->get($path));

        $zip = new \ZipArchive;
        if ($zip->open($tmpZip) !== true) {
            @unlink($tmpZip);
            $this->error('Gagal membuka file backup (zip rusak / terenkripsi?).');

            return self::FAILURE;
        }

        $sqlEntry = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (preg_match('#db-dumps/.+\.sql$#i', $name) || preg_match('#\.sql$#i', $name)) {
                $sqlEntry = $name;
                break;
            }
        }
        if ($sqlEntry === null) {
            $zip->close();
            @unlink($tmpZip);
            $this->error('Backup tidak berisi dump database (.sql).');

            return self::FAILURE;
        }

        $tmpSql = tempnam(sys_get_temp_dir(), 'restore-sql-');
        file_put_contents($tmpSql, $zip->getFromName($sqlEntry));
        $zip->close();

        // Import via client mariadb (MYSQL_PWD agar password tak tampil di proses).
        $db = config('database.connections.mysql');
        $cmd = sprintf(
            'mariadb --host=%s --port=%s --user=%s --skip-ssl %s < %s',
            escapeshellarg((string) $db['host']),
            escapeshellarg((string) ($db['port'] ?? 3306)),
            escapeshellarg((string) $db['username']),
            escapeshellarg($dbName),
            escapeshellarg($tmpSql),
        );
        $proc = Process::fromShellCommandline($cmd, base_path(), ['MYSQL_PWD' => (string) $db['password']], null, 1800);
        $proc->run();

        @unlink($tmpZip);
        @unlink($tmpSql);

        if (! $proc->isSuccessful()) {
            $this->error('Import gagal: '.trim($proc->getErrorOutput() ?: $proc->getOutput()));

            return self::FAILURE;
        }

        $this->info("Restore selesai dari {$path} → database '{$dbName}'.");

        return self::SUCCESS;
    }
}
