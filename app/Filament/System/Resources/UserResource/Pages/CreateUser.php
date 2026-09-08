<?php

namespace App\Filament\System\Resources\UserResource\Pages;

use App\Filament\System\Resources\RoleResource;
use App\Filament\System\Resources\UserResource;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

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
        // Hak akses khusus = direct permission pengguna (di luar Grup Akses).
        $this->record->syncPermissions(RoleResource::hanyaIzinYangAda($this->pendingPerms));
    }
}
