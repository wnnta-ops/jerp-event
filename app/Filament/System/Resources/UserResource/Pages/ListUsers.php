<?php

namespace App\Filament\System\Resources\UserResource\Pages;

use App\Filament\System\Resources\UserResource;
use App\Filament\System\Support\ImporPengguna;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use RuntimeException;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('unduhFormat')
                ->label('Unduh Format')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->visible(fn () => UserResource::canCreate())
                ->action(fn () => response()->download(ImporPengguna::format(), 'format-import-pengguna.xlsx')->deleteFileAfterSend()),

            Actions\Action::make('imporExcel')
                ->label('Import Excel')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('gray')
                ->visible(fn () => UserResource::canCreate())
                ->modalHeading('Import Pengguna dari Excel')
                ->modalDescription('Satu baris = satu pengguna, dicocokkan lewat email: yang sudah ada diperbarui, yang belum dibuat. Grup Akses & Grup Akses BO yang diisi menggantikan grup pengguna itu; sel kosong = tidak diubah. Password hanya dipakai untuk pengguna baru. Seluruh berkas diperiksa dulu — satu baris salah, tidak ada yang disimpan.')
                ->modalSubmitActionLabel('Import')
                ->form([
                    Forms\Components\FileUpload::make('berkas')
                        ->label('Berkas Excel (.xlsx)')
                        ->acceptedFileTypes(['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])
                        ->storeFiles(false)
                        ->required(),
                ])
                ->action(function (array $data, Actions\Action $action) {
                    try {
                        $h = ImporPengguna::jalankan($data['berkas']->getRealPath(), auth()->user());
                    } catch (RuntimeException $e) {
                        Notification::make()->danger()->title('Import gagal')->body(nl2br(e($e->getMessage())))->persistent()->send();
                        $action->halt();

                        return;
                    }

                    $isi = "{$h['baru']} pengguna baru, {$h['ubah']} diperbarui. "
                        ."Karyawan BO: {$h['karyawan_baru']} dibuat, {$h['karyawan_ubah']} disesuaikan.";
                    if ($h['gagal_bo'] !== []) {
                        Notification::make()->warning()->title('Import selesai, sebagian gagal sinkron ke BO')
                            ->body($isi.'<br>Gagal ke BO (ulangi lewat "Sinkronkan ke BO"):<br>'.implode('<br>', array_map('e', $h['gagal_bo'])))
                            ->persistent()->send();

                        return;
                    }
                    Notification::make()->success()->title('Import selesai')->body($isi.' Berlaku di BO paling lama 1 menit.')->send();
                }),

            Actions\CreateAction::make(),
        ];
    }
}
