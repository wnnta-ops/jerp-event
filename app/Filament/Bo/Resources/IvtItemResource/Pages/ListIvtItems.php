<?php

namespace App\Filament\Bo\Resources\IvtItemResource\Pages;

use App\Filament\Bo\Resources\IvtItemResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListIvtItems extends ListRecords
{
    protected static string $resource = IvtItemResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
