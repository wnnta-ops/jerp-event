<?php

namespace App\Filament\Fo\Resources\TtsResource\Pages;

use App\Filament\Fo\Resources\TtsResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditTts extends EditRecord
{
    protected static string $resource = TtsResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
