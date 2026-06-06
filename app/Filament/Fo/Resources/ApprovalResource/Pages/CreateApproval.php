<?php

namespace App\Filament\Fo\Resources\ApprovalResource\Pages;

use App\Filament\Fo\Resources\ApprovalResource;
use App\Services\Numerator\NumeratorServiceInterface;
use Filament\Resources\Pages\CreateRecord;

class CreateApproval extends CreateRecord
{
    protected static string $resource = ApprovalResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (empty($data['no_approval'])) {
            $data['no_approval'] = app(NumeratorServiceInterface::class)->next('APPROVAL');
        }
        if (empty($data['requested_by'])) {
            $data['requested_by'] = auth()->id();
        }

        return $data;
    }
}
