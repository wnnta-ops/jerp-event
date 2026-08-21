<?php

namespace App\Filament\Backup\Widgets;

use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Spatie\Backup\BackupDestination\BackupDestination;

class BackupStats extends BaseWidget
{
    protected function getStats(): array
    {
        $name = config('backup.backup.name', config('app.name'));
        $disks = config('backup.backup.destination.disks', ['local']);

        $totalBackups = 0;
        $totalSize = 0;
        $latest = null;
        $disksUsed = [];

        foreach ($disks as $disk) {
            try {
                $destination = BackupDestination::create($disk, $name);
                $backups = $destination->backups();
                $totalBackups += $backups->count();
                $totalSize += $backups->sum(fn ($b) => (int) $b->size());

                $newest = $destination->newestBackup();
                if ($newest && (! $latest || $newest->date()->gt($latest))) {
                    // Spatie menyusun tanggalnya dari mtime tanpa menyebut zona waktu,
                    // jadi hasilnya UTC. Tanpa konversi, backup jam 00:00 WIB tampil
                    // 17:00 hari sebelumnya — seolah jadwalnya meleset 7 jam.
                    $latest = $newest->date()->setTimezone(config('app.timezone'));
                }

                $disksUsed[] = $disk;
            } catch (\Throwable $e) {
                // disk not configured / unreachable — skip
            }
        }

        return [
            Stat::make('Jumlah backup', $totalBackups)
                ->description($disksUsed ? 'Disk: '.implode(', ', $disksUsed) : 'Belum ada disk aktif')
                ->descriptionIcon('heroicon-m-server-stack')
                ->color('success'),

            Stat::make('Total ukuran', self::humanSize($totalSize))
                ->description('Akumulasi semua arsip backup')
                ->descriptionIcon('heroicon-m-archive-box')
                ->color('info'),

            Stat::make('Backup terakhir', $latest ? $latest->translatedFormat('d M Y H:i') : 'Belum ada')
                ->description($latest ? $latest->diffForHumans() : 'Jalankan backup pertama Anda')
                ->descriptionIcon('heroicon-m-clock')
                ->color($latest ? ($latest->diffInHours() > 24 ? 'warning' : 'success') : 'danger'),
        ];
    }

    private static function humanSize(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = (int) floor(log($bytes, 1024));
        $i = min($i, count($units) - 1);

        return number_format($bytes / (1024 ** $i), 2).' '.$units[$i];
    }
}
