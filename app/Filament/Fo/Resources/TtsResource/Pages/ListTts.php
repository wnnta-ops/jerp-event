<?php

namespace App\Filament\Fo\Resources\TtsResource\Pages;

use App\Filament\Fo\Resources\TtsResource;
use Filament\Resources\Pages\ListRecords;

class ListTts extends ListRecords
{
    protected static string $resource = TtsResource::class;

    protected function getHeaderActions(): array
    {
        // TTS terbit otomatis dari SPRS — tidak boleh dibuat manual (tanpa tombol Buat TTS).
        return [];
    }
}
