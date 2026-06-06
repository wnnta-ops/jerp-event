<?php

namespace App\Filament\Bo\Resources\SupplierResource\Pages;

use App\Filament\Bo\Resources\SupplierResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSupplier extends EditRecord
{
    protected static string $resource = SupplierResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()->visible(fn ($record) => ! $record->invoices()->exists())];
    }
}
