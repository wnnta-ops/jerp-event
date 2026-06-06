<?php

namespace App\Filament\Fo\Resources\SalesLevelResource\Pages;

use App\Filament\Fo\Resources\SalesLevelResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSalesLevel extends EditRecord
{
    protected static string $resource = SalesLevelResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
