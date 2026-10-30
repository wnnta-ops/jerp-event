<?php

namespace App\Filament\Fo\Resources\TemplateDokumenResource\Pages;

use App\Filament\Fo\Resources\TemplateDokumenResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListTemplateDokumen extends ListRecords
{
    protected static string $resource = TemplateDokumenResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Versi baru dibuat di editor visual (per halaman, seperti hasil cetak).
            Actions\CreateAction::make()->label('Buat Versi Baru')->url(route('fo.template-dokumen.editor')),
            Actions\Action::make('formulir')->label('Buat (mode formulir)')->color('gray')
                ->visible(fn () => TemplateDokumenResource::canCreate())
                ->url(TemplateDokumenResource::getUrl('create')),
        ];
    }

    public function getSubheading(): ?string
    {
        return 'Belum ada versi = SPR/SPRS dicetak dengan teks bawaan aplikasi. Versi yang sudah berlaku terkunci; ubah dengan membuat versi baru.';
    }
}
