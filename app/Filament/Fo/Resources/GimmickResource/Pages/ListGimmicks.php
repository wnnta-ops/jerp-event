<?php

namespace App\Filament\Fo\Resources\GimmickResource\Pages;

use App\Filament\Fo\Resources\GimmickResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListGimmicks extends ListRecords
{
    protected static string $resource = GimmickResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
