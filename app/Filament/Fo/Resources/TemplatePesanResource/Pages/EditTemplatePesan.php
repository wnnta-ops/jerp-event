<?php

namespace App\Filament\Fo\Resources\TemplatePesanResource\Pages;

use App\Filament\Fo\Resources\TemplatePesanResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditTemplatePesan extends EditRecord
{
    protected static string $resource = TemplatePesanResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (array_key_exists('wa_params', $data)) {
            $data['wa_params'] = TemplatePesanResource::urutkanParam($data['wa_params'] ?? null);
        }

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }
}
