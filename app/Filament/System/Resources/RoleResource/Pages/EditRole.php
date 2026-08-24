<?php

namespace App\Filament\System\Resources\RoleResource\Pages;

use App\Filament\System\Resources\RoleResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditRole extends EditRecord
{
    protected static string $resource = RoleResource::class;

    /** @var array<int,string> */
    protected array $pendingPerms = [];

    /** Potret izin sebelum disimpan — sumber untuk mempertahankan yang di luar matriks. */
    protected $izinSebelumnya;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }

    /** Muat matrix dari permission role yang ada. */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $m = [];
        foreach ($this->record->permissions as $p) {
            $m[str_replace('.', '~', $p->name)] = true;
        }
        $data['m'] = $m;

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->izinSebelumnya = $this->record->permissions()->get();
        $this->pendingPerms = RoleResource::collectPermissions($data['m'] ?? []);
        unset($data['m']);

        return $data;
    }

    protected function afterSave(): void
    {
        // Izin yang tak punya kotak di matriks ini (mis. seluruh izin BO) TIDAK
        // boleh ikut terhapus hanya karena halaman ini tidak bisa menampilkannya.
        $this->record->syncPermissions(
            RoleResource::gabungIzin($this->izinSebelumnya, $this->pendingPerms)
        );
    }
}
