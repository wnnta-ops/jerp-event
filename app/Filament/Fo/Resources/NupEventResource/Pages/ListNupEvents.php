<?php

namespace App\Filament\Fo\Resources\NupEventResource\Pages;

use App\Filament\Fo\Resources\NupEventResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListNupEvents extends ListRecords
{
    protected static string $resource = NupEventResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
