<?php

namespace App\Filament\Fo\Resources\TahapResource\Pages;

use App\Filament\Fo\Resources\TahapResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListTahap extends ListRecords
{
    protected static string $resource = TahapResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
