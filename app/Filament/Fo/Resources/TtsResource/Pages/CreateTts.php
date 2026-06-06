<?php

namespace App\Filament\Fo\Resources\TtsResource\Pages;

use App\Filament\Fo\Resources\TtsResource;
use App\Services\Numerator\NumeratorServiceInterface;
use Filament\Resources\Pages\CreateRecord;

class CreateTts extends CreateRecord
{
    protected static string $resource = TtsResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (empty($data['no_tts'])) {
            $data['no_tts'] = app(NumeratorServiceInterface::class)->next('TTS');
        }

        return $data;
    }
}
