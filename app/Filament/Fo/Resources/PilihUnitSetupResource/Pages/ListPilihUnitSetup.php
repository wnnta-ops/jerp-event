<?php

namespace App\Filament\Fo\Resources\PilihUnitSetupResource\Pages;

use App\Filament\Fo\Resources\PilihUnitSetupResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPilihUnitSetup extends ListRecords
{
    protected static string $resource = PilihUnitSetupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
