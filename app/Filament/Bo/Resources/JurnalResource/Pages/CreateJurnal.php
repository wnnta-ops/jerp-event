<?php

namespace App\Filament\Bo\Resources\JurnalResource\Pages;

use App\Filament\Bo\Resources\JurnalResource;
use App\Filament\Bo\Resources\JurnalResource\Pages\Concerns\BalancesJurnal;
use Filament\Resources\Pages\CreateRecord;

class CreateJurnal extends CreateRecord
{
    use BalancesJurnal;

    protected static string $resource = JurnalResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $this->normaliseJurnal($data, isCreate: true);
    }
}
