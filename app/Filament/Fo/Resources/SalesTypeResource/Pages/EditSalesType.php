<?php

namespace App\Filament\Fo\Resources\SalesTypeResource\Pages;

use App\Filament\Fo\Resources\SalesTypeResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSalesType extends EditRecord
{
    protected static string $resource = SalesTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
