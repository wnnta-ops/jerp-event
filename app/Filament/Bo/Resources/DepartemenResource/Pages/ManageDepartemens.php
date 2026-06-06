<?php

namespace App\Filament\Bo\Resources\DepartemenResource\Pages;

use App\Filament\Bo\Resources\DepartemenResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageDepartemens extends ManageRecords
{
    protected static string $resource = DepartemenResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
