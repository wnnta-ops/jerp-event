<?php

namespace App\Filament\System\Resources\BoRoleResource\Pages;

use App\Filament\System\Resources\BoRoleResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListBoRoles extends ListRecords
{
    protected static string $resource = BoRoleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Grup Akses BO Baru'),
        ];
    }
}
