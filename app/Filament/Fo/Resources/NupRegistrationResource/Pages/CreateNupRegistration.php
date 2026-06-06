<?php

namespace App\Filament\Fo\Resources\NupRegistrationResource\Pages;

use App\Filament\Fo\Resources\NupRegistrationResource;
use App\Modules\FO\Launching\Models\NupRegistration;
use App\Services\Numerator\NumeratorServiceInterface;
use Filament\Resources\Pages\CreateRecord;

class CreateNupRegistration extends CreateRecord
{
    protected static string $resource = NupRegistrationResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (empty($data['no_nup'])) {
            $data['no_nup'] = app(NumeratorServiceInterface::class)->next('NUP');
        }
        if (empty($data['urutan'])) {
            $data['urutan'] = (int) (NupRegistration::withoutGlobalScopes()
                ->where('nup_event_id', $data['nup_event_id'] ?? 0)
                ->max('urutan') ?? 0) + 1;
        }

        return $data;
    }
}
