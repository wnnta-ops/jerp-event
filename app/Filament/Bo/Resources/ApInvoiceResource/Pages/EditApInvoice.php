<?php

namespace App\Filament\Bo\Resources\ApInvoiceResource\Pages;

use App\Filament\Bo\Resources\ApInvoiceResource;
use Filament\Resources\Pages\EditRecord;

class EditApInvoice extends EditRecord
{
    protected static string $resource = ApInvoiceResource::class;

    protected function authorizeAccess(): void
    {
        parent::authorizeAccess();

        abort_unless($this->record->status === 'DRAFT', 403, 'Hanya invoice DRAFT yang bisa diubah.');
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['total'] = (float) ($data['nominal'] ?? 0) + (float) ($data['ppn'] ?? 0) - (float) ($data['pph'] ?? 0);
        $data['sisa'] = $data['total'];

        return $data;
    }
}
