<?php

namespace App\Filament\Fo\Resources\CustomerResource\Pages;

use App\Filament\Fo\Resources\CustomerResource;
use App\Modules\FO\MarketingSales\Exports\CustomerExport;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Maatwebsite\Excel\Facades\Excel;

class ListCustomers extends ListRecords
{
    protected static string $resource = CustomerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('export')->label('Export Excel')->icon('heroicon-o-arrow-down-tray')->color('gray')
                // Ikut filter & pencarian yang sedang aktif.
                ->action(fn () => Excel::download(
                    new CustomerExport($this->getFilteredSortedTableQuery()->with(['project', 'bank'])->limit(50000)->get()),
                    'master-customer-'.now()->format('Ymd-His').'.xlsx',
                )),
            Actions\CreateAction::make(),
        ];
    }
}
