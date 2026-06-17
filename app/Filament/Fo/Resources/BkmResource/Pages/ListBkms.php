<?php

namespace App\Filament\Fo\Resources\BkmResource\Pages;

use App\Filament\Fo\Resources\BkmResource;
use Filament\Resources\Pages\ListRecords;

class ListBkms extends ListRecords
{
    protected static string $resource = BkmResource::class;

    protected function getHeaderActions(): array
    {
        // Kwitansi terbit otomatis dari alur pembayaran — tidak boleh dibuat manual.
        return [];
    }
}
