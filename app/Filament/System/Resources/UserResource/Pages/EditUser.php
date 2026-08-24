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

    /** Potret hak khusus sebelum disimpan — sumber untuk mempertahankan yang di luar matriks. */
    protected $izinSebelumnya;

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
        $this->izinSebelumnya = $this->record->getDirectPermissions();
        $this->pendingPerms = RoleResource::collectPermissions($data['m'] ?? []);
        unset($data['m']);

        return $data;
    }

    protected function afterSave(): void
    {
        // Sama seperti Grup Akses: hak khusus yang tak punya kotak di matriks ini
        // (mis. izin BO) tidak boleh terhapus hanya karena halaman ini tak bisa
        // menampilkannya.
        $this->record->syncPermissions(
            RoleResource::gabungIzin($this->izinSebelumnya, $this->pendingPerms)
        );
    }
}
