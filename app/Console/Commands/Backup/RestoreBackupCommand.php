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
 *
 * MULTI-DB: satu backup bisa berisi beberapa dump (mis. jerp.sql = FO, ipro_bo.sql =
 * BO). Tiap .sql dipetakan ke database-nya sendiri berdasar nama file (database_dump_
 * filename_base = 'database'). Backup lama (1 .sql) tetap kompatibel → masuk ke 'jerp'.
 */
class RestoreBackupCommand extends Command
{
    protected $signature = 'backup:restore
        {path : Path relatif file .zip backup pada disk}
        {--disk= : Disk backup (default dari config backup)}
        {--force : Lewati konfirmasi interaktif}
        {--no-safety-backup : Lewati backup darurat sebelum restore}
        {--only-db : Jangan pulihkan berkas, database saja}';

    protected $description = 'Restore database (FO + BO) + berkas storage dari file backup zip (MENIMPA data saat ini).';

    public function handle(): int
    {
        $disk = $this->option('disk') ?: (config('backup.backup.destination.disks')[0] ?? 'local');
        $path = $this->argument('path');
        $storage = Storage::disk($disk);

        // Koneksi target yang mungkin ada di backup: FO 'mysql' (jerp) + BO 'ipro_bo'.
        $targets = [];
        foreach (['mysql', 'ipro_bo'] as $conn) {
            $cfg = config("database.connections.{$conn}");
            if (is_array($cfg) && ($cfg['driver'] ?? '') === 'mysql' && ! empty($cfg['database'])) {
                $targets[$conn] = $cfg;
            }
        }
        $dbNames = implode(', ', array_map(fn ($c) => $c['database'], $targets));

        if (! $storage->exists($path)) {
            $this->error("File backup tidak ditemukan: [{$disk}] {$path}");

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm("Restore akan MENIMPA database ({$dbNames}) dari {$path}. Lanjutkan?")) {
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

        // Salin zip ke temp lokal (mendukung disk apa pun), lalu ekstrak SEMUA db-dump .sql.
        $tmpZip = tempnam(sys_get_temp_dir(), 'restore-zip-');
        file_put_contents($tmpZip, $storage->get($path));

        $zip = new \ZipArchive;
        if ($zip->open($tmpZip) !== true) {
            @unlink($tmpZip);
            $this->error('Gagal membuka file backup (zip rusak / terenkripsi?).');

            return self::FAILURE;
        }

        $sqlEntries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (preg_match('#\.sql$#i', $name)) {
                $sqlEntries[] = $name;
            }
        }
        if (empty($sqlEntries)) {
            $zip->close();
            @unlink($tmpZip);
            $this->error('Backup tidak berisi dump database (.sql).');

            return self::FAILURE;
        }

        // Pemetaan tiap .sql → koneksi target (dari nama database di nama file).
        $restored = [];
        $failed = [];
        foreach ($sqlEntries as $entry) {
            $base = strtolower(basename($entry));
            $conn = null;
            foreach ($targets as $c => $cfg) {
                if (str_contains($base, strtolower((string) $cfg['database']))) {
                    $conn = $c;
                    break;
                }
            }
            // Kompat backup lama (1 file, nama tak cocok) → default ke 'mysql' (jerp).
            if ($conn === null) {
                if (count($sqlEntries) === 1 && isset($targets['mysql'])) {
                    $conn = 'mysql';
                } else {
                    $this->warn("Lewati {$entry} — tak cocok database mana pun.");

                    continue;
                }
            }
            $cfg = $targets[$conn];
            $dbName = (string) $cfg['database'];

            $tmpSql = tempnam(sys_get_temp_dir(), 'restore-sql-');
            file_put_contents($tmpSql, $zip->getFromName($entry));

            // Import via client mariadb (MYSQL_PWD agar password tak tampil di proses).
            $cmd = sprintf(
                'mariadb --host=%s --port=%s --user=%s --skip-ssl %s < %s',
                escapeshellarg((string) $cfg['host']),
                escapeshellarg((string) ($cfg['port'] ?? 3306)),
                escapeshellarg((string) $cfg['username']),
                escapeshellarg($dbName),
                escapeshellarg($tmpSql),
            );
            $proc = Process::fromShellCommandline($cmd, base_path(), ['MYSQL_PWD' => (string) $cfg['password']], null, 1800);
            $proc->run();
            @unlink($tmpSql);

            if ($proc->isSuccessful()) {
                $restored[] = $dbName;
                $this->info("✓ {$dbName} dipulihkan dari {$entry}");
            } else {
                $failed[] = $dbName;
                $this->error("✗ Import {$dbName} gagal: ".trim($proc->getErrorOutput() ?: $proc->getOutput()));
            }
        }

        // ── Pulihkan BERKAS (storage/app) ──────────────────────────────────────
        // Tanpa ini, restore hanya mengembalikan database sementara berkas yang
        // diunggah setelah backup (KTP/NPWP, siteplan) tetap tertinggal — data dan
        // berkas jadi tidak sinkron: baris pendaftaran hilang, lampirannya masih ada.
        $fileHasil = ['pulih' => 0, 'lewat' => 0];
        if (! $this->option('only-db')) {
            $fileHasil = $this->pulihkanBerkas($zip);
        }

        $zip->close();
        @unlink($tmpZip);

        if (! empty($failed)) {
            $this->error('Restore ada yang GAGAL: '.implode(', ', $failed).'. Berhasil: '.(implode(', ', $restored) ?: '—'));

            return self::FAILURE;
        }

        $this->info("Restore selesai dari {$path} → database: ".implode(', ', $restored).'.');
        if (! $this->option('only-db')) {
            $this->info("Berkas dipulihkan: {$fileHasil['pulih']}".($fileHasil['lewat'] > 0 ? " (dilewati: {$fileHasil['lewat']})" : ''));
        }

        return self::SUCCESS;
    }

    /**
     * Salin kembali isi storage/app dari dalam zip.
     *
     * Spatie menyimpan berkas memakai jalur absolut tanpa garis miring depan,
     * mis. "var/www/html/storage/app/nup/1/ktp.jpg". Yang dicari adalah entri di
     * bawah storage/app, lalu ditulis ulang ke lokasi yang sama.
     *
     * MENIMPA berkas bernama sama, tetapi TIDAK menghapus berkas yang tidak ada di
     * backup — sengaja: menghapus isi storage berarti menghancurkan berkas yang sah
     * bila seseorang salah memilih arsip. Sisa berkas yatim dibersihkan manual.
     *
     * @return array{pulih:int,lewat:int}
     */
    private function pulihkanBerkas(\ZipArchive $zip): array
    {
        $tujuanRoot = rtrim(storage_path('app'), '/\\');
        // Cocokkan dari ruas "storage/app/" agar tetap jalan meski jalur absolut
        // server backup berbeda dengan server tempat restore dijalankan.
        $penanda = 'storage/app/';
        $pulih = 0;
        $lewat = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entri = (string) $zip->getNameIndex($i);

            if (str_ends_with($entri, '/') || preg_match('#\.sql$#i', $entri)) {
                continue;
            }

            $pos = strpos($entri, $penanda);
            if ($pos === false) {
                continue; // di luar storage/app (mis. db-dumps) — bukan urusan kita
            }

            $relatif = substr($entri, $pos + strlen($penanda));

            // Tolak jalur yang mencoba keluar dari storage/app.
            if ($relatif === '' || str_contains($relatif, '..')) {
                $lewat++;

                continue;
            }

            $tujuan = $tujuanRoot.'/'.$relatif;
            $dir = dirname($tujuan);
            if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
                $lewat++;

                continue;
            }

            $isi = $zip->getFromIndex($i);
            if ($isi === false || @file_put_contents($tujuan, $isi) === false) {
                $lewat++;

                continue;
            }

            $pulih++;
        }

        return ['pulih' => $pulih, 'lewat' => $lewat];
    }
}
