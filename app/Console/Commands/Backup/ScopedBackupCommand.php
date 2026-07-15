<?php

namespace App\Console\Commands\Backup;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * Backup sesuai CAKUPAN (dipakai jadwal & bisa manual):
 *   FULL   → APP (file) + DB FO + DB BO
 *   DB_ALL → DB FO + DB BO (tanpa file)
 *   DB_FO  → hanya DB FO (jerp)
 *   DB_BO  → hanya DB BO (ipro_bo)
 *   APP    → hanya file aplikasi (tanpa DB)
 *
 * Bekerja dengan meng-override daftar koneksi database Spatie Backup di runtime,
 * lalu memanggil `backup:run` dengan flag yang sesuai (in-process → override berlaku).
 */
class ScopedBackupCommand extends Command
{
    protected $signature = 'backup:scoped {scope=DB_ALL : FULL|DB_ALL|DB_FO|DB_BO|APP}';

    protected $description = 'Jalankan backup sesuai cakupan (DB FO / BO / keduanya / APP).';

    public function handle(): int
    {
        $scope = strtoupper((string) $this->argument('scope'));
        $fo = (string) env('DB_CONNECTION', 'mysql'); // koneksi FO (database jerp)
        $bo = 'ipro_bo';                                // koneksi BO / Bankloan

        $args = match ($scope) {
            'APP' => ['--only-files' => true],
            'DB_FO' => $this->useDatabases([$fo]) + ['--only-db' => true],
            'DB_BO' => $this->useDatabases([$bo]) + ['--only-db' => true],
            'FULL' => $this->useDatabases([$fo, $bo]) + [],
            default => $this->useDatabases([$fo, $bo]) + ['--only-db' => true], // DB_ALL
        };

        $this->info('Backup cakupan: '.$scope);

        return Artisan::call('backup:run', $args, $this->getOutput());
    }

    /** Set daftar koneksi DB yang di-backup untuk proses ini. @return array<string,mixed> */
    private function useDatabases(array $connections): array
    {
        config(['backup.backup.source.databases' => $connections]);

        return [];
    }
}
