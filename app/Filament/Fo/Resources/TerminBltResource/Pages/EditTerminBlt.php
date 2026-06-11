<?php

namespace App\Filament\Fo\Resources\TerminBltResource\Pages;

use App\Filament\Fo\Resources\TerminBltResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditTerminBlt extends EditRecord
{
    protected static string $resource = TerminBltResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }
}
