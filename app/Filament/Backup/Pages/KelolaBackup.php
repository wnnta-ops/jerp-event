<?php

namespace App\Filament\Backup\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

class KelolaBackup extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-archive-box-arrow-down';

    protected static ?string $navigationLabel = 'Kelola Backup';

    protected static ?string $title = 'Kelola Backup';

    protected static ?int $navigationSort = 10;

    protected static string $view = 'filament.backup.pages.kelola-backup';

    public array $backupRows = [];

    /** Path lokasi (root) disk backup — ditampilkan di banner. */
    public string $backupLocation = '';

    public function mount(): void
    {
        $this->refreshList();
    }

    public function refreshList(): void
    {
        $disks = config('backup.backup.destination.disks', ['local']);

        // Path lokasi (root) disk backup pertama — untuk banner.
        $this->backupLocation = '';
        try {
            $this->backupLocation = rtrim(Storage::disk($disks[0] ?? 'local')->path(''), '/\\');
        } catch (\Throwable) {
            // biarkan kosong bila disk tak mendukung path()
        }

        $rows = [];
        foreach ($disks as $disk) {
            try {
                foreach (Storage::disk($disk)->allFiles() as $file) {
                    if (! str_ends_with(strtolower($file), '.zip')) {
                        continue;
                    }
                    $ts = Storage::disk($disk)->lastModified($file);
                    $bytes = (int) Storage::disk($disk)->size($file);
                    try {
                        $location = Storage::disk($disk)->path($file);  // path lokasi absolut di server
                    } catch (\Throwable) {
                        $location = $file;
                    }
                    $rows[] = [
                        'disk' => $disk,
                        'path' => $file,
                        'filename' => $file,    // path relatif (folder + nama) — agar backup gladi terlihat
                        'location' => $location, // path lokasi absolut (pengganti nama disk di tabel)
                        'bytes' => $bytes,      // ukuran mentah — untuk total & sort di view
                        'size' => self::humanSize($bytes),
                        'date' => Carbon::createFromTimestamp($ts)->translatedFormat('d M Y H:i'),
                        'ts' => $ts,
                    ];
                }
            } catch (\Throwable $e) {
                // skip disk
            }
        }

        usort($rows, fn ($a, $b) => $b['ts'] <=> $a['ts']);
        $this->backupRows = $rows;
    }

    /** Unduh satu file backup .zip dari disk tujuan. */
    public function download(string $disk, string $path)
    {
        abort_unless(
            str_ends_with(strtolower($path), '.zip') && Storage::disk($disk)->exists($path),
            404,
            'File backup tidak ditemukan.'
        );

        return Storage::disk($disk)->download($path, basename($path));
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
                        Artisan::call('backup:run');  // backup penuh (DB + file); --only-db adalah flag, jangan kirim nilai
                        Notification::make()->title('Backup selesai')->body('Cek list di bawah untuk file backup terbaru.')->success()->send();
                        $this->refreshList();
                    } catch (\Throwable $e) {
                        Notification::make()->title('Backup gagal')->body($e->getMessage())->danger()->send();
                    }
                }),

            // Backup HANYA APP (file/kode + storage), tanpa database.
            Action::make('backupFiles')
                ->label('Backup App (file saja)')
                ->icon('heroicon-o-folder-arrow-down')
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription('Backup HANYA file aplikasi (kode + storage), tanpa database. Cocok bila hanya ingin snapshot berkas.')
                ->action(function () {
                    try {
                        Artisan::call('backup:run', ['--only-files' => true]);
                        Notification::make()->title('Backup app (file) selesai')->body('Snapshot file aplikasi dibuat (tanpa database).')->success()->send();
                        $this->refreshList();
                    } catch (\Throwable $e) {
                        Notification::make()->title('Backup file gagal')->body($e->getMessage())->danger()->send();
                    }
                }),

            // Backup HANYA database (FO + BO), tanpa file — lebih cepat & kecil.
            Action::make('backupDb')
                ->label('Backup DB saja')
                ->icon('heroicon-o-circle-stack')
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription('Backup HANYA database (FO + BO), tanpa file aplikasi. Lebih cepat & kecil.')
                ->action(function () {
                    try {
                        Artisan::call('backup:run', ['--only-db' => true]);
                        Notification::make()->title('Backup database selesai')->body('Snapshot database (jerp + ipro_bo) dibuat.')->success()->send();
                        $this->refreshList();
                    } catch (\Throwable $e) {
                        Notification::make()->title('Backup DB gagal')->body($e->getMessage())->danger()->send();
                    }
                }),

            // Backup manual untuk gladi kotor — disimpan ke folder custom (dibuat otomatis).
            Action::make('backupGladi')
                ->label('Backup Manual / Gladi')
                ->icon('heroicon-o-camera')
                ->color('primary')
                ->modalDescription('Buat snapshot ke folder khusus sebelum gladi kotor. Setelah gladi, gunakan Restore untuk mengembalikan data.')
                ->form([
                    TextInput::make('folder')->label('Nama folder snapshot')->required()
                        ->default('gladi-'.now()->format('Y-m-d'))
                        ->helperText('Folder dibuat otomatis di disk backup. Contoh: gladi-2026-06-12.')
                        ->rule('regex:/^[A-Za-z0-9_\-\/]+$/')
                        ->validationMessages(['regex' => 'Hanya huruf, angka, -, _, / yang diizinkan.']),
                ])
                ->action(function (array $data) {
                    $folder = trim($data['folder']);
                    try {
                        config(['backup.backup.name' => $folder]);
                        Artisan::call('backup:run');  // backup penuh (DB + file); --only-db adalah flag, jangan kirim nilai
                        Notification::make()->title('Snapshot gladi dibuat')->body('Tersimpan di folder: '.$folder)->success()->send();
                        $this->refreshList();
                    } catch (\Throwable $e) {
                        Notification::make()->title('Backup gladi gagal')->body($e->getMessage())->danger()->send();
                    }
                }),

            // Restore — MENIMPA database dari file backup terpilih. Pengaman berlapis.
            Action::make('restore')
                ->label('Restore')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('danger')
                ->modalHeading('Restore Database (menimpa data saat ini)')
                ->modalDescription('Seluruh isi database akan DITIMPA oleh backup terpilih. Pastikan ini saat yang tepat (mis. setelah gladi kotor).')
                ->modalSubmitActionLabel('Restore Sekarang')
                ->form([
                    Select::make('backup')->label('Pilih file backup')->required()->searchable()
                        ->options(collect($this->backupRows)->mapWithKeys(
                            fn ($r) => [$r['disk'].'|'.$r['path'] => $r['path'].'  ('.$r['date'].' · '.$r['size'].')']
                        )->all())
                        ->helperText('Pilih snapshot yang dibuat sebelum gladi.'),
                    Toggle::make('safety')->label('Buat backup darurat dulu (disarankan)')->default(true)
                        ->helperText('Jaring pengaman bila salah pilih file.'),
                    TextInput::make('confirm')->label('Ketik RESTORE untuk konfirmasi')->required()
                        ->rule('in:RESTORE')->validationMessages(['in' => 'Ketik persis: RESTORE'])
                        ->autocomplete(false),
                ])
                ->action(function (array $data) {
                    [$disk, $path] = explode('|', $data['backup'], 2);
                    try {
                        $code = Artisan::call('backup:restore', array_filter([
                            'path' => $path,
                            '--disk' => $disk,
                            '--force' => true,
                            '--no-safety-backup' => ! ($data['safety'] ?? true),
                        ], fn ($v) => $v !== false && $v !== null));
                        $out = trim(Artisan::output());
                        if ($code === 0) {
                            Notification::make()->title('Restore selesai')->body($out ?: 'Database dipulihkan.')->success()->persistent()->send();
                        } else {
                            Notification::make()->title('Restore gagal')->body($out ?: 'Lihat log.')->danger()->persistent()->send();
                        }
                        $this->refreshList();
                    } catch (\Throwable $e) {
                        Notification::make()->title('Restore gagal')->body($e->getMessage())->danger()->persistent()->send();
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
        // Info dipindah ke banner di body agar header tidak sesak (lihat blade).
        return null;
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
