<?php

namespace App\Filament\Backup\Pages;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Artisan;
use Spatie\Backup\BackupDestination\BackupDestination;

class KelolaBackup extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-archive-box-arrow-down';

    protected static ?string $navigationLabel = 'Kelola Backup';

    protected static ?string $title = 'Kelola Backup';

    protected static ?int $navigationSort = 10;

    protected static string $view = 'filament.backup.pages.kelola-backup';

    public array $backupRows = [];

    public function mount(): void
    {
        $this->refreshList();
    }

    public function refreshList(): void
    {
        $name = config('backup.backup.name', config('app.name'));
        $disks = config('backup.backup.destination.disks', ['local']);

        $rows = [];
        foreach ($disks as $disk) {
            try {
                $destination = BackupDestination::create($disk, $name);
                foreach ($destination->backups()->sortByDesc(fn ($b) => $b->date()) as $backup) {
                    $rows[] = [
                        'disk' => $disk,
                        'path' => $backup->path(),
                        'filename' => basename($backup->path()),
                        'size' => self::humanSize((int) $backup->size()),
                        'date' => optional($backup->date())->translatedFormat('d M Y H:i'),
                    ];
                }
            } catch (\Throwable $e) {
                // skip disk
            }
        }

        $this->backupRows = $rows;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('runBackup')
                ->label('Jalankan backup sekarang')
                ->icon('heroicon-o-play')
                ->color('success')
                ->requiresConfirmation()
                ->modalDescription('Backup penuh akan dijalankan (database + storage). Proses bisa beberapa menit.')
                ->action(function () {
                    try {
                        Artisan::call('backup:run', ['--only-db' => false]);
                        Notification::make()
                            ->title('Backup selesai')
                            ->body('Cek list di bawah untuk file backup terbaru.')
                            ->success()
                            ->send();
                        $this->refreshList();
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('Backup gagal')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),

            Action::make('cleanup')
                ->label('Bersihkan (retention)')
                ->icon('heroicon-o-trash')
                ->color('warning')
                ->requiresConfirmation()
                ->modalDescription('Jalankan retention cleanup — backup lama yang melewati batas usia akan dihapus.')
                ->action(function () {
                    try {
                        Artisan::call('backup:clean');
                        Notification::make()->title('Cleanup selesai')->success()->send();
                        $this->refreshList();
                    } catch (\Throwable $e) {
                        Notification::make()->title('Cleanup gagal')->body($e->getMessage())->danger()->send();
                    }
                }),

            Action::make('refresh')
                ->label('Refresh')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->action(fn () => $this->refreshList()),
        ];
    }

    public function getHeading(): string|Htmlable
    {
        return 'Kelola Backup';
    }

    public function getSubheading(): string|Htmlable|null
    {
        $name = config('backup.backup.name', config('app.name'));

        return 'Backup name: '.$name.' · Disk: '.implode(', ', config('backup.backup.destination.disks', ['local']));
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
