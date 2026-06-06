<?php

namespace App\Filament\Fo\Resources\CustomerResource\Pages;

use App\Filament\Fo\Resources\CustomerResource;
use App\Services\Numerator\NumeratorServiceInterface;
use Filament\Resources\Pages\CreateRecord;

class CreateCustomer extends CreateRecord
{
    protected static string $resource = CustomerResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (empty($data['code'])) {
            $data['code'] = app(NumeratorServiceInterface::class)->next('CUST');
        }

        return $data;
    }
}
