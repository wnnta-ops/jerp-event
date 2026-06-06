<?php

namespace App\Filament\Bo\Resources\CbVoucherResource\Pages;

use App\Filament\Bo\Resources\CbVoucherResource;
use App\Modules\BO\CashBank\Services\CbVoucherService;
use Filament\Resources\Pages\CreateRecord;

class CreateCbVoucher extends CreateRecord
{
    protected static string $resource = CbVoucherResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['no_voucher'] = app(CbVoucherService::class)->nextNumber($data['jenis'], (int) $data['company_id']);
        $data['status'] = 'DRAFT';

        return $data;
    }
}
