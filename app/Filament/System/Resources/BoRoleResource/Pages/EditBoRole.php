<?php

namespace App\Filament\System\Resources\BoRoleResource\Pages;

use App\Filament\System\Resources\BoRoleResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditBoRole extends EditRecord
{
    protected static string $resource = BoRoleResource::class;

    /** @var array<int,int> */
    protected array $permIds = [];

    /** Isi state matrix 'perm' dari izin role, dikelompokkan per area (module__area). */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $grouped = [];
        foreach ($this->record->permissions as $p) {
            $grouped[BoRoleResource::areaKeyFor($p->name, (string) $p->module)][] = $p->id;
        }
        $data['perm'] = $grouped;

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->permIds = BoRoleResource::collectPermissionIds($data['perm'] ?? []);
        unset($data['perm']);

        return $data;
    }

    protected function afterSave(): void
    {
        $this->record->permissions()->sync($this->permIds);
        BoRoleResource::flashCacheNotice();
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->hidden(fn () => ! BoRoleResource::bolehDihapus($this->record))
                ->modalDescription(fn () => BoRoleResource::peringatanHapus($this->record)),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
