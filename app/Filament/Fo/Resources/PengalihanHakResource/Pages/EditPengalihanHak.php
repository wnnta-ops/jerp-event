<?php

namespace App\Filament\Fo\Resources\PengalihanHakResource\Pages;

use App\Filament\Fo\Resources\PengalihanHakResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPengalihanHak extends EditRecord
{
    protected static string $resource = PengalihanHakResource::class;

    protected static ?string $title = 'Pengalihan Hak — Kontrak';

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('pengalihan')->label('Pengalihan Hak')->icon('heroicon-o-identification')->color('primary')
                ->modalHeading('Pengalihan Hak')->modalWidth('2xl')->modalSubmitActionLabel('Proses Pengalihan')
                ->form(PengalihanHakResource::pengalihanForm())
                ->action(fn (array $data) => PengalihanHakResource::runPengalihan($this->record, $data)),
        ];
    }

    /** Halaman read-only (info kontrak) + riwayat di bawah — tak ada simpan. */
    protected function getFormActions(): array
    {
        return [];
    }
}
