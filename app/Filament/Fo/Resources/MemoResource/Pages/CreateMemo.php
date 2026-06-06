<?php

namespace App\Filament\Fo\Resources\MemoResource\Pages;

use App\Filament\Fo\Resources\MemoResource;
use App\Services\Numerator\NumeratorServiceInterface;
use Filament\Resources\Pages\CreateRecord;

class CreateMemo extends CreateRecord
{
    protected static string $resource = MemoResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (empty($data['no_memo'])) {
            $data['no_memo'] = app(NumeratorServiceInterface::class)->next('MEMO');
        }

        return $data;
    }
}
