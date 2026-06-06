<?php

namespace App\Filament\Bo\Resources\ApInvoiceResource\Pages;

use App\Filament\Bo\Resources\ApInvoiceResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListApInvoices extends ListRecords
{
    protected static string $resource = ApInvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()->label('Invoice baru')];
    }
}
