<?php

namespace App\Filament\Fo\Resources\SkemaKomisiResource\Pages;

use App\Filament\Fo\Resources\SkemaKomisiResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSkemaKomisi extends EditRecord
{
    protected static string $resource = SkemaKomisiResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }
}
