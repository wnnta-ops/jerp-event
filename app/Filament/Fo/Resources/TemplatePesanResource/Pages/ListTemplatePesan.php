<?php

namespace App\Filament\Fo\Resources\TemplatePesanResource\Pages;

use App\Filament\Fo\Resources\TemplatePesanResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListTemplatePesan extends ListRecords
{
    protected static string $resource = TemplatePesanResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()->label('Tambah Template Pesan')];
    }
}
