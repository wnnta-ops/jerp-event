<?php

namespace App\Filament\Fo\Resources\NupRegistrationResource\Pages;

use App\Filament\Fo\Resources\NupRegistrationResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListNupRegistrations extends ListRecords
{
    protected static string $resource = NupRegistrationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
