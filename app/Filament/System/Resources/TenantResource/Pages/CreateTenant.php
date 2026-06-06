<?php

namespace App\Filament\System\Resources\TenantResource\Pages;

use App\Filament\System\Resources\TenantResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTenant extends CreateRecord
{
    protected static string $resource = TenantResource::class;
}
