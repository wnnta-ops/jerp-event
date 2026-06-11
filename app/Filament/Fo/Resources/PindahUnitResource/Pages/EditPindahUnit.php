<?php

namespace App\Filament\Fo\Resources\PindahUnitResource\Pages;

use App\Filament\Fo\Resources\PindahUnitResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPindahUnit extends EditRecord
{
    protected static string $resource = PindahUnitResource::class;

    protected static ?string $title = 'Pindah Unit — Kontrak';

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('pindah')->label('Pindah Unit')->icon('heroicon-o-arrows-right-left')->color('primary')
                ->url(fn () => '/fo/pindah-unit-pilih?kontrak='.$this->record->id),
        ];
    }

    /** Halaman read-only (info kontrak) + riwayat di bawah — tak ada simpan. */
    protected function getFormActions(): array
    {
        return [];
    }
}
