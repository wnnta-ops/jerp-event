<?php

namespace App\Filament\Fo\Resources\VaResource\Pages;

use App\Filament\Fo\Resources\VaResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditVa extends EditRecord
{
    protected static string $resource = VaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
