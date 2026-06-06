<?php

namespace App\Filament\Bo\Resources\IvtMovementResource\Pages;

use App\Filament\Bo\Resources\IvtMovementResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListIvtMovements extends ListRecords
{
    protected static string $resource = IvtMovementResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()->label('Movement baru')];
    }
}
