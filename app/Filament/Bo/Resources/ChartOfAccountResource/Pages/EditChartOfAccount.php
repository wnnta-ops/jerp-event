<?php

namespace App\Filament\Bo\Resources\ChartOfAccountResource\Pages;

use App\Filament\Bo\Resources\ChartOfAccountResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditChartOfAccount extends EditRecord
{
    protected static string $resource = ChartOfAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->visible(fn ($record) => ! $record->jurnalDetils()->exists()),
        ];
    }
}
