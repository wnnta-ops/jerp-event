<?php

namespace App\Filament\Fo\Resources\MemoResource\Pages;

use App\Filament\Fo\Resources\MemoResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListMemos extends ListRecords
{
    protected static string $resource = MemoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
