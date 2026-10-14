<?php

namespace App\Filament\System\Resources\UserResource\Pages;

use App\Filament\System\Resources\UserResource;
use App\Filament\System\Support\ImporPengguna;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

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

            // Semua pengguna dalam format Import: edit di Excel, lalu unggah ulang.
            Actions\Action::make('eksporExcel')
                ->label('Export Excel')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->visible(fn () => UserResource::canCreate())
                ->action(fn () => response()->download(ImporPengguna::ekspor(), 'pengguna-'.now()->format('Y-m-d').'.xlsx')->deleteFileAfterSend()),

            Actions\Action::make('imporExcel')
                ->label('Import Excel')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('gray')
                ->visible(fn () => UserResource::canCreate())
                ->modalHeading('Import Pengguna dari Excel')
                ->modalDescription('Satu baris = satu pengguna, dicocokkan lewat email: yang sudah ada diperbarui, yang belum dibuat. Grup Akses & Grup Akses BO yang diisi menggantikan grup pengguna itu; sel kosong = tidak diubah. Password hanya dipakai untuk pengguna baru. Seluruh berkas diperiksa dulu — satu sel salah, tidak ada yang disimpan.')
                // Isi dialognya form upload BIASA ke ImporPenggunaController — FileUpload
                // Livewire di server kehilangan berkasnya saat dikirim ("field is required").
                ->modalContent(view('filament.system.impor-pengguna'))
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Tutup'),

            Actions\CreateAction::make(),
        ];
    }
}
