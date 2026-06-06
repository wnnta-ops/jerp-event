<?php

namespace App\Filament\Fo\Resources\ApprovalResource\Pages;

use App\Filament\Fo\Resources\ApprovalResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListApprovals extends ListRecords
{
    protected static string $resource = ApprovalResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
