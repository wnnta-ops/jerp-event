<?php

namespace App\Filament\Bo\Resources\PurchaseRequestResource\Pages;

use App\Filament\Bo\Resources\PurchaseRequestResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPurchaseRequests extends ListRecords
{
    protected static string $resource = PurchaseRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()->label('PR baru')];
    }
}
