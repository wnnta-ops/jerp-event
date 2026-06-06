<?php

namespace App\Filament\System\Resources\NumeratorResource\Pages;

use App\Filament\System\Resources\NumeratorResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListNumerators extends ListRecords
{
    protected static string $resource = NumeratorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
