<?php

namespace App\Filament\Fo\Resources\SkemaBltResource\Pages;

use App\Filament\Fo\Resources\SkemaBltResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSkemaBlts extends ListRecords
{
    protected static string $resource = SkemaBltResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()->label('Pendaftaran Skema Baru')];
    }
}
