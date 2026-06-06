<?php

namespace App\Filament\Fo\Resources\KomisiResource\Pages;

use App\Filament\Fo\Resources\KomisiResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListKomisis extends ListRecords
{
    protected static string $resource = KomisiResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
