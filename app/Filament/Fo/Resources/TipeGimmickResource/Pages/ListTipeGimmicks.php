<?php

namespace App\Filament\Fo\Resources\TipeGimmickResource\Pages;

use App\Filament\Fo\Resources\TipeGimmickResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListTipeGimmicks extends ListRecords
{
    protected static string $resource = TipeGimmickResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
