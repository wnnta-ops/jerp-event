<?php

namespace App\Filament\Fo\Resources\CollectionResource\Pages;

use App\Filament\Fo\Resources\CollectionResource;
use Filament\Resources\Pages\ListRecords;

class ListCollections extends ListRecords
{
    protected static string $resource = CollectionResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
