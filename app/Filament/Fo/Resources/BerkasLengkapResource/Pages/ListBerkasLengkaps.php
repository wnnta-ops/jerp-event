<?php

namespace App\Filament\Fo\Resources\BerkasLengkapResource\Pages;

use App\Filament\Fo\Resources\BerkasLengkapResource;
use Filament\Resources\Pages\ListRecords;

class ListBerkasLengkaps extends ListRecords
{
    protected static string $resource = BerkasLengkapResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
