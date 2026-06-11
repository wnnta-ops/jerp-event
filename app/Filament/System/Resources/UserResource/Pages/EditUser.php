<?php

namespace App\Filament\System\Resources\UserResource\Pages;

use App\Filament\System\Resources\RoleResource;
use App\Filament\System\Resources\UserResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    /** @var array<int,string> */
    protected array $pendingPerms = [];

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }

    /** Muat hak akses khusus (direct permission) ke matrix. */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $m = [];
        foreach ($this->record->getDirectPermissions() as $p) {
            $m[str_replace('.', '~', $p->name)] = true;
        }
        $data['m'] = $m;

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->pendingPerms = RoleResource::collectPermissions($data['m'] ?? []);
        unset($data['m']);

        return $data;
    }

    protected function afterSave(): void
    {
        $this->record->syncPermissions($this->pendingPerms);
    }
}
