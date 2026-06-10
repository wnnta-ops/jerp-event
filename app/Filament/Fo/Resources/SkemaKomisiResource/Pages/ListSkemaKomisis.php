<?php

namespace App\Filament\Fo\Resources\SkemaKomisiResource\Pages;

use App\Filament\Fo\Resources\SkemaKomisiResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSkemaKomisis extends ListRecords
{
    protected static string $resource = SkemaKomisiResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
