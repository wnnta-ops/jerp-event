<?php

namespace App\Filament\Fo\Resources\TipeTarifResource\Pages;

use App\Filament\Fo\Resources\TipeTarifResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListTipeTarifs extends ListRecords
{
    protected static string $resource = TipeTarifResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
