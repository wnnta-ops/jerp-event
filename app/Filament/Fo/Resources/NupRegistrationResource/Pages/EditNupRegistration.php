<?php

namespace App\Filament\Fo\Resources\NupRegistrationResource\Pages;

use App\Filament\Fo\Resources\NupRegistrationResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditNupRegistration extends EditRecord
{
    protected static string $resource = NupRegistrationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
