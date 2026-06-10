<?php

namespace App\Filament\Fo\Resources\TerminKomisiResource\Pages;

use App\Filament\Fo\Resources\TerminKomisiResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditTerminKomisi extends EditRecord
{
    protected static string $resource = TerminKomisiResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }
}
