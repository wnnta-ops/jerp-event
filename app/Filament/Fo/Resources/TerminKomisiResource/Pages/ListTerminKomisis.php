<?php

namespace App\Filament\Fo\Resources\TerminKomisiResource\Pages;

use App\Filament\Fo\Resources\TerminKomisiResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListTerminKomisis extends ListRecords
{
    protected static string $resource = TerminKomisiResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
