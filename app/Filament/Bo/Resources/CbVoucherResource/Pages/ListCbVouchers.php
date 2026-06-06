<?php

namespace App\Filament\Bo\Resources\CbVoucherResource\Pages;

use App\Filament\Bo\Resources\CbVoucherResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListCbVouchers extends ListRecords
{
    protected static string $resource = CbVoucherResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Voucher baru'),
        ];
    }
}
