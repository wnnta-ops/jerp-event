<?php

namespace App\Filament\Event\Resources\EventResource\Pages;

use App\Filament\Event\Resources\EventResource;
use App\Modules\Event\Services\EventDraftService;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListEvents extends ListRecords
{
    protected static string $resource = EventResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label(fn () => auth()->user() && app(EventDraftService::class)->ambil(auth()->user())
                ? 'Lanjutkan draft event'
                : 'Buat event'),
        ];
    }
}
