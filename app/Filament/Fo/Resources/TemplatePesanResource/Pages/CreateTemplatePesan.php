<?php

namespace App\Filament\Fo\Resources\TemplatePesanResource\Pages;

use App\Filament\Fo\Resources\TemplatePesanResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTemplatePesan extends CreateRecord
{
    protected static string $resource = TemplatePesanResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['wa_params'] = TemplatePesanResource::urutkanParam($data['wa_params'] ?? null);

        return $data;
    }
}
