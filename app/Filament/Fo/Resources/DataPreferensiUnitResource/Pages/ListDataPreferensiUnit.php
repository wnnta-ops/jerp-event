<?php

namespace App\Filament\Fo\Resources\DataPreferensiUnitResource\Pages;

use App\Filament\Fo\Resources\DataPreferensiUnitResource;
use App\Modules\FO\Launching\Exports\DataPreferensiUnitExport;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Maatwebsite\Excel\Facades\Excel;

class ListDataPreferensiUnit extends ListRecords
{
    protected static string $resource = DataPreferensiUnitResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('export')->label('Download Excel')->icon('heroicon-o-arrow-down-tray')->color('gray')
                // Ikut filter & pencarian yang sedang aktif.
                ->action(fn () => Excel::download(
                    new DataPreferensiUnitExport($this->getFilteredSortedTableQuery()->with(['customer', 'projectPref'])->limit(20000)->get()),
                    'data-preferensi-unit-'.now()->format('Ymd-His').'.xlsx',
                )),
        ];
    }
}
