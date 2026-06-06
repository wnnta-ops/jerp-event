<?php

namespace App\Filament\Fo\Resources\BkmResource\Pages;

use App\Filament\Fo\Resources\BkmResource;
use App\Services\Numerator\NumeratorServiceInterface;
use Filament\Resources\Pages\CreateRecord;

class CreateBkm extends CreateRecord
{
    protected static string $resource = BkmResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (empty($data['no_bkm'])) {
            $data['no_bkm'] = app(NumeratorServiceInterface::class)->next('BKM');
        }

        return $data;
    }
}
