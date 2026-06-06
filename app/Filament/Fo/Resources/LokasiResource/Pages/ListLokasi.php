<?php

namespace App\Filament\Fo\Resources\LokasiResource\Pages;

use App\Filament\Fo\Resources\LokasiResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListLokasi extends ListRecords
{
    protected static string $resource = LokasiResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
