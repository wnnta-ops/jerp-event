<?php

namespace App\Filament\System\Resources\BoRoleResource\Pages;

use App\Filament\System\Resources\BoRoleResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBoRole extends CreateRecord
{
    protected static string $resource = BoRoleResource::class;

    /** @var array<int,int> */
    protected array $permIds = [];

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->permIds = BoRoleResource::collectPermissionIds($data['perm'] ?? []);
        unset($data['perm']);

        $data['guard_name'] = $data['guard_name'] ?: 'web';
        $data['tenant_id'] = $data['tenant_id'] ?? BoRoleResource::defaultTenantId();
        $data['is_system'] = false;

        return $data;
    }

    protected function afterCreate(): void
    {
        $this->record->permissions()->sync($this->permIds);
        BoRoleResource::flashCacheNotice();
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
