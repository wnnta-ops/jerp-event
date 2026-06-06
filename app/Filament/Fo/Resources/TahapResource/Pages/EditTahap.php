<?php

namespace App\Filament\Fo\Resources\TahapResource\Pages;

use App\Filament\Fo\Resources\TahapResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditTahap extends EditRecord
{
    protected static string $resource = TahapResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }
}
