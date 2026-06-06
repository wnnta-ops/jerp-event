<?php

namespace App\Filament\Fo\Resources\NupTipeResource\Pages;

use App\Filament\Fo\Resources\NupTipeResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListNupTipe extends ListRecords
{
    protected static string $resource = NupTipeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
