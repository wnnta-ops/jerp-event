<?php

namespace App\Filament\Bo\Resources\JurnalResource\Pages;

use App\Filament\Bo\Resources\JurnalResource;
use App\Filament\Bo\Resources\JurnalResource\Pages\Concerns\BalancesJurnal;
use Filament\Resources\Pages\EditRecord;

class EditJurnal extends EditRecord
{
    use BalancesJurnal;

    protected static string $resource = JurnalResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->normaliseJurnal($data, isCreate: false);
    }

    protected function authorizeAccess(): void
    {
        parent::authorizeAccess();

        abort_unless($this->record->status === 'DRAFT', 403, 'Hanya jurnal DRAFT yang bisa diubah.');
    }
}
