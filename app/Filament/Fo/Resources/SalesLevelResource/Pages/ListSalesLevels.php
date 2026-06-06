<?php

namespace App\Filament\Fo\Resources\SalesLevelResource\Pages;

use App\Filament\Fo\Resources\SalesLevelResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSalesLevels extends ListRecords
{
    protected static string $resource = SalesLevelResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
