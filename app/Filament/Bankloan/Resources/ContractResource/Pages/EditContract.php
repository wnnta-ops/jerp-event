<?php

namespace App\Filament\Bankloan\Resources\ContractResource\Pages;

use App\Filament\Bankloan\Resources\ContractResource;
use Filament\Resources\Pages\EditRecord;

class EditContract extends EditRecord
{
    protected static string $resource = ContractResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
