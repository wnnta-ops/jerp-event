<?php

namespace App\Filament\Fo\Resources\PilihUnitSetupResource\Pages;

use App\Filament\Fo\Resources\PilihUnitSetupResource;
use Filament\Resources\Pages\EditRecord;

class EditPilihUnitSetup extends EditRecord
{
    protected static string $resource = PilihUnitSetupResource::class;

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
