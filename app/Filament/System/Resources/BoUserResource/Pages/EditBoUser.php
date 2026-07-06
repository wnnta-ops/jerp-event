<?php

namespace App\Filament\System\Resources\BoUserResource\Pages;

use App\Filament\System\Resources\BoRoleResource;
use App\Filament\System\Resources\BoUserResource;
use Filament\Resources\Pages\EditRecord;

class EditBoUser extends EditRecord
{
    protected static string $resource = BoUserResource::class;

    protected function afterSave(): void
    {
        // Perubahan role/status tersimpan ke ipro_bo; ingatkan reset cache izin BO.
        BoRoleResource::flashCacheNotice();
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
