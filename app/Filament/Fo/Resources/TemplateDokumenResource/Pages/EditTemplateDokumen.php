<?php

namespace App\Filament\Fo\Resources\TemplateDokumenResource\Pages;

use App\Filament\Fo\Resources\TemplateDokumenResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditTemplateDokumen extends EditRecord
{
    protected static string $resource = TemplateDokumenResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('visual')->label('Editor visual')->icon('heroicon-o-document-text')->color('primary')
                ->url(fn () => route('fo.template-dokumen.editor', $this->record)),
            Actions\Action::make('pratinjau')->label('Pratinjau')->icon('heroicon-o-eye')->color('gray')
                ->url(fn () => route('fo.cetak.template-dokumen', $this->record), shouldOpenInNewTab: true),
            Actions\DeleteAction::make(),
        ];
    }
}
