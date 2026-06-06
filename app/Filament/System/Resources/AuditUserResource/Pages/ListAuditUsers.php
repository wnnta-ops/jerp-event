<?php

namespace App\Filament\System\Resources\AuditUserResource\Pages;

use App\Filament\System\Resources\AuditUserResource;
use Filament\Resources\Pages\ListRecords;

class ListAuditUsers extends ListRecords
{
    protected static string $resource = AuditUserResource::class;
}
