<?php

namespace App\Filament\Bo\Resources\PurchaseRequestResource\Pages;

use App\Filament\Bo\Resources\PurchaseRequestResource;
use App\Filament\Bo\Resources\PurchaseRequestResource\Pages\Concerns\ComputesPrTotals;
use App\Modules\BO\PurchaseRequest\Services\PurchaseRequestService;
use Filament\Resources\Pages\CreateRecord;

class CreatePurchaseRequest extends CreateRecord
{
    use ComputesPrTotals;

    protected static string $resource = PurchaseRequestResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['no_pr'] = app(PurchaseRequestService::class)->nextPrNumber((int) $data['company_id']);
        $data['status'] = 'DRAFT';
        $data['approval_level'] = 0;

        return $this->computeTotals($data);
    }
}
