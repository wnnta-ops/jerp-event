<?php

namespace App\Filament\Event\Resources\EventResource\Pages;

use App\Filament\Event\Resources\EventResource;
use App\Modules\Event\Services\EventSetupService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\CreateRecord\Concerns\HasWizard;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

class CreateEvent extends CreateRecord
{
    use HasWizard;

    protected static string $resource = EventResource::class;

    protected static ?string $title = 'Buat event';

    protected function getSteps(): array
    {
        return EventResource::wizardSteps();
    }

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(EventSetupService::class)->create($data, Auth::user());
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title('Event belum tersimpan')->body($e->getMessage())->send();
            throw new Halt;
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
