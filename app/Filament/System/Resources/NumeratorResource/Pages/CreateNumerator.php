<?php

namespace App\Filament\System\Resources\NumeratorResource\Pages;

use App\Filament\System\Resources\NumeratorResource;
use Filament\Resources\Pages\CreateRecord;

class CreateNumerator extends CreateRecord
{
    protected static string $resource = NumeratorResource::class;

    /** Tanpa reset → baris berperiode kosong; itulah baris yang dibaca NumeratorService. */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (($data['reset'] ?? null) === 'tidak') {
            $data['period'] = '';
        } else {
            $data['reset'] = null;
        }

        return $data;
    }
}
