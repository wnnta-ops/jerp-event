<?php

namespace App\Filament\Bo\Resources\CbVoucherResource\Pages;

use App\Filament\Bo\Resources\CbVoucherResource;
use Filament\Resources\Pages\EditRecord;

class EditCbVoucher extends EditRecord
{
    protected static string $resource = CbVoucherResource::class;

    protected function authorizeAccess(): void
    {
        parent::authorizeAccess();

        abort_unless($this->record->status === 'DRAFT', 403, 'Hanya voucher DRAFT yang bisa diubah.');
    }
}
