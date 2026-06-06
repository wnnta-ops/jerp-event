<?php

namespace App\Filament\Bo\Resources\ArInvoiceResource\Pages;

use App\Filament\Bo\Resources\ArInvoiceResource;
use Filament\Resources\Pages\EditRecord;

class EditArInvoice extends EditRecord
{
    protected static string $resource = ArInvoiceResource::class;

    protected function authorizeAccess(): void
    {
        parent::authorizeAccess();

        abort_unless($this->record->status === 'DRAFT', 403, 'Hanya invoice DRAFT yang bisa diubah.');
    }
}
