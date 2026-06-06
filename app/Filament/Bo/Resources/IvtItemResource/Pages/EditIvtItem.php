<?php

namespace App\Filament\Bo\Resources\IvtItemResource\Pages;

use App\Filament\Bo\Resources\IvtItemResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditIvtItem extends EditRecord
{
    protected static string $resource = IvtItemResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()->visible(fn ($record) => ! $record->movements()->exists())];
    }
}
