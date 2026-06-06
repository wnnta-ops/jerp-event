<?php

namespace App\Filament\Bo\Resources\IvtMovementResource\Pages;

use App\Filament\Bo\Resources\IvtMovementResource;
use App\Modules\BO\Inventory\Models\IvtMovement;
use App\Modules\BO\Inventory\Services\InventoryService;
use Filament\Resources\Pages\CreateRecord;

class CreateIvtMovement extends CreateRecord
{
    protected static string $resource = IvtMovementResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['no_movement'] = app(InventoryService::class)->nextNumber();
        $data['arah'] = $data['arah'] ?? IvtMovement::arahFor($data['jenis']);
        $data['status'] = 'DRAFT';
        $data['applied'] = false;

        return $data;
    }
}
