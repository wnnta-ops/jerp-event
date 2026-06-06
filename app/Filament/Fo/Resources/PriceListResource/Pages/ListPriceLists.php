<?php

namespace App\Filament\Fo\Resources\PriceListResource\Pages;

use App\Filament\Fo\Resources\PriceListResource;
use Filament\Resources\Pages\ListRecords;

class ListPriceLists extends ListRecords
{
    protected static string $resource = PriceListResource::class;

    protected function getHeaderActions(): array
    {
        // Harga diedit per unit lewat tombol "Edit Harga" di tiap baris.
        return [];
    }
}
