<?php

namespace App\Filament\System\Resources\RoleResource\Pages;

use App\Filament\System\Resources\RoleResource;
use Filament\Resources\Pages\CreateRecord;

class CreateRole extends CreateRecord
{
    protected static string $resource = RoleResource::class;

    /** @var array<int,string> */
    protected array $pendingPerms = [];

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->pendingPerms = RoleResource::collectPermissions($data['m'] ?? []);
        unset($data['m']);

        return $data;
    }

    protected function afterCreate(): void
    {
        $this->record->syncPermissions($this->pendingPerms);
    }
}
