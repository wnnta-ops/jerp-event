<?php

namespace App\Filament\Bo\Resources\IvtLocationResource\Pages;

use App\Filament\Bo\Resources\IvtLocationResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageIvtLocations extends ManageRecords
{
    protected static string $resource = IvtLocationResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
