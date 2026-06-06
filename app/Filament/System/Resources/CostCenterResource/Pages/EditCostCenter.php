<?php

namespace App\Filament\System\Resources\CostCenterResource\Pages;

use App\Filament\System\Resources\CostCenterResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditCostCenter extends EditRecord
{
    protected static string $resource = CostCenterResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
