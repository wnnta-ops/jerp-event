<?php

namespace App\Filament\Bo\Resources\ArInvoiceResource\Pages;

use App\Filament\Bo\Resources\ArInvoiceResource;
use App\Modules\BO\AccountsReceivable\Services\ArInvoiceService;
use Filament\Resources\Pages\CreateRecord;

class CreateArInvoice extends CreateRecord
{
    protected static string $resource = ArInvoiceResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['no_invoice'] = app(ArInvoiceService::class)->nextNumber((int) $data['company_id']);
        $data['status'] = 'DRAFT';
        $data['sisa'] = $data['nominal'] ?? 0;

        return $data;
    }
}
