<?php

namespace App\Filament\System\Resources\NumeratorResource\Pages;

use App\Filament\System\Resources\NumeratorResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditNumerator extends EditRecord
{
    protected static string $resource = NumeratorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    /** Tanpa reset → baris berperiode kosong; itulah baris yang dibaca NumeratorService. */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (($data['reset'] ?? null) === 'tidak') {
            $data['period'] = '';
        } else {
            $data['reset'] = null;
        }

        return $data;
    }
}
