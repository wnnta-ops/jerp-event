<?php

namespace App\Filament\Bo\Resources\IvtMovementResource\Pages;

use App\Filament\Bo\Resources\IvtMovementResource;
use Filament\Resources\Pages\EditRecord;

class EditIvtMovement extends EditRecord
{
    protected static string $resource = IvtMovementResource::class;

    protected function authorizeAccess(): void
    {
        parent::authorizeAccess();

        abort_unless($this->record->status === 'DRAFT', 403, 'Hanya movement DRAFT yang bisa diubah.');
    }
}
