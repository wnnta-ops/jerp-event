<?php

namespace App\Filament\Fo\Resources\SkemaBltResource\Pages;

use App\Filament\Fo\Resources\SkemaBltResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSkemaBlt extends EditRecord
{
    protected static string $resource = SkemaBltResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }
}
