<?php

namespace App\Filament\Fo\Resources\SkemaResource\Pages;

use App\Filament\Fo\Pages\SkemaWizard;
use App\Filament\Fo\Resources\SkemaResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSkemas extends ListRecords
{
    protected static string $resource = SkemaResource::class;

    protected function getHeaderActions(): array
    {
        // Buat skema lewat Wizard (rumus) — bukan grid manual. Hasil generate
        // lalu bisa di-fine-tune di halaman Edit (grid termin).
        return [
            Actions\Action::make('wizard')
                ->label('Buat Skema (Wizard)')
                ->icon('heroicon-o-sparkles')
                ->url(fn () => SkemaWizard::getUrl()),
        ];
    }
}
