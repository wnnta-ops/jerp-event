<?php

namespace App\Filament\Fo\Resources\TerminBltResource\Pages;

use App\Filament\Fo\Resources\TerminBltResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListTerminBlts extends ListRecords
{
    protected static string $resource = TerminBltResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()->label('Pendaftaran Termin Baru')];
    }
}
