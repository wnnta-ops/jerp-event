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

        // Bidang grup BO sengaja disembunyikan saat membuat (akun BO dicocokkan
        // lewat email, jadi belum ada apa pun untuk ditaut). Dibuang untuk jaga-jaga:
        // grup_bo bukan kolom tabel users, dan lolosnya berarti gagal insert.
        unset($data['grup_bo']);

        return $data;
    }

    protected function afterCreate(): void
    {
        // Hak akses khusus = direct permission pengguna (di luar Grup Akses).
        $this->record->syncPermissions(RoleResource::hanyaIzinYangAda($this->pendingPerms));
    }
}
