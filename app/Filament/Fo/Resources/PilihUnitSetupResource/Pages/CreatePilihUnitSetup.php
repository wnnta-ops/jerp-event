<?php

namespace App\Filament\Fo\Resources\PilihUnitSetupResource\Pages;

use App\Filament\Fo\Resources\PilihUnitSetupResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePilihUnitSetup extends CreateRecord
{
    protected static string $resource = PilihUnitSetupResource::class;

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
