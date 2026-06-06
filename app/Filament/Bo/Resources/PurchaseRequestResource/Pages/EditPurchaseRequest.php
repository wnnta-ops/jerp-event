<?php

namespace App\Filament\Bo\Resources\PurchaseRequestResource\Pages;

use App\Filament\Bo\Resources\PurchaseRequestResource;
use App\Filament\Bo\Resources\PurchaseRequestResource\Pages\Concerns\ComputesPrTotals;
use Filament\Resources\Pages\EditRecord;

class EditPurchaseRequest extends EditRecord
{
    use ComputesPrTotals;

    protected static string $resource = PurchaseRequestResource::class;

    protected function authorizeAccess(): void
    {
        parent::authorizeAccess();

        abort_unless($this->record->status === 'DRAFT', 403, 'Hanya PR DRAFT yang bisa diubah.');
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->computeTotals($data);
    }
}
