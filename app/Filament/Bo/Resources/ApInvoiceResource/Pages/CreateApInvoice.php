<?php

namespace App\Filament\Bo\Resources\ApInvoiceResource\Pages;

use App\Filament\Bo\Resources\ApInvoiceResource;
use App\Modules\BO\AccountsPayable\Services\ApInvoiceService;
use Filament\Resources\Pages\CreateRecord;

class CreateApInvoice extends CreateRecord
{
    protected static string $resource = ApInvoiceResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['no_invoice'] = app(ApInvoiceService::class)->nextNumber((int) $data['company_id']);
        $data['status'] = 'DRAFT';
        $data['total'] = (float) ($data['nominal'] ?? 0) + (float) ($data['ppn'] ?? 0) - (float) ($data['pph'] ?? 0);
        $data['sisa'] = $data['total'];

        return $data;
    }
}
