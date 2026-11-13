<?php

namespace App\Filament\Event\Resources\EventResource\Pages;

use App\Filament\Event\Resources\EventResource;
use App\Modules\Event\Models\EtEvent;
use App\Modules\Event\Services\EventSetupService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\Pages\EditRecord\Concerns\HasWizard;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Ubah event lewat wizard yang sama. Setelah event dibuka, langkah Pos & Titik
 * scan terkunci (hanya detail & akses yang bisa diubah).
 */
class EditEvent extends EditRecord
{
    use HasWizard;

    protected static string $resource = EventResource::class;

    protected static ?string $title = 'Ubah event';

    protected function getSteps(): array
    {
        return EventResource::wizardSteps();
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var EtEvent $event */
        $event = $this->getRecord();

        return app(EventSetupService::class)->formData($event) + ['buka_sekarang' => false];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var EtEvent $record */
        try {
            return app(EventSetupService::class)->update($record, $data);
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title('Perubahan belum tersimpan')->body($e->getMessage())->send();
            throw new Halt;
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
