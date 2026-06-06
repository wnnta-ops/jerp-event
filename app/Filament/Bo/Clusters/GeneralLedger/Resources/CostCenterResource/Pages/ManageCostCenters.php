<?php

namespace App\Filament\Bo\Clusters\GeneralLedger\Resources\CostCenterResource\Pages;

use App\Filament\Bo\Clusters\GeneralLedger\Resources\CostCenterResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageCostCenters extends ManageRecords
{
    protected static string $resource = CostCenterResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
