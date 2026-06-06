<?php

namespace App\Filament\Fo\Resources\GimmickResource\Pages;

use App\Filament\Fo\Resources\GimmickResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditGimmick extends EditRecord
{
    protected static string $resource = GimmickResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
