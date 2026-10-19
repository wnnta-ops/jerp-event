<?php

namespace App\Filament\Fo\Resources\WaTemplateResource\Pages;

use App\Filament\Fo\Resources\WaTemplateResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageWaTemplates extends ManageRecords
{
    protected static string $resource = WaTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()->label('Tambah Template WA')];
    }
}
