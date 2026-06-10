<?php

namespace App\Filament\Fo\Resources\SprResource\Pages;

use App\Filament\Fo\Resources\SprResource;
use Filament\Resources\Pages\ListRecords;

class ListSprs extends ListRecords
{
    protected static string $resource = SprResource::class;

    // SPR terbentuk dari finalisasi SPRS — tidak ada tombol Buat di sini.
    protected function getHeaderActions(): array
    {
        return [];
    }
}
