<?php

namespace App\Filament\Fo\Resources\KomisiResource\Pages;

use App\Filament\Fo\Resources\KomisiResource;
use App\Services\Numerator\NumeratorServiceInterface;
use Filament\Resources\Pages\CreateRecord;

class CreateKomisi extends CreateRecord
{
    protected static string $resource = KomisiResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (empty($data['no_komisi'])) {
            $data['no_komisi'] = app(NumeratorServiceInterface::class)->next('KOMISI');
        }

        return $data;
    }
}
