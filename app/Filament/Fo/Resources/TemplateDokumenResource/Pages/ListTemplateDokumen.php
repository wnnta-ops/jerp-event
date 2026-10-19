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
        return [Actions\CreateAction::make()->label('Buat Versi Baru')];
    }

    public function getSubheading(): ?string
    {
        return 'Belum ada versi = SPR/SPRS dicetak dengan teks bawaan aplikasi. Versi yang sudah berlaku terkunci; ubah dengan membuat versi baru.';
    }
}
