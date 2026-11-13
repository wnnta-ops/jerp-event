<?php

namespace App\Filament\Event\Resources\EventResource\Pages;

use App\Filament\Event\Resources\EventResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListEvents extends ListRecords
{
    protected static string $resource = EventResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Buat event'),
        ];
    }
}
