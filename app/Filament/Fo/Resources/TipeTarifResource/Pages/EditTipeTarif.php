<?php

namespace App\Filament\Fo\Resources\TipeTarifResource\Pages;

use App\Filament\Fo\Resources\TipeTarifResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditTipeTarif extends EditRecord
{
    protected static string $resource = TipeTarifResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }
}
