<?php

namespace App\Filament\Fo\Resources\LogPesanResource\Pages;

use App\Filament\Fo\Resources\LogPesanResource;
use App\Modules\FO\Komunikasi\Exports\LogPesanExport;
use App\Modules\FO\Komunikasi\Models\LogPesan;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Facades\Excel;

class ListLogPesan extends ListRecords
{
    protected static string $resource = LogPesanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('export')->label('Export Excel')->icon('heroicon-o-arrow-down-tray')->color('gray')
                // Ikut tab, filter & pencarian yang sedang aktif.
                ->action(fn () => Excel::download(
                    new LogPesanExport($this->getFilteredSortedTableQuery()->limit(20000)->get()),
                    'monitoring-pesan-'.now()->format('Ymd-His').'.xlsx',
                )),
        ];
    }

    public function getTabs(): array
    {
        $hitung = LogPesan::query()->where('created_at', '>=', now()->subDays(30))
            ->selectRaw('status, COUNT(*) n')->groupBy('status')->pluck('n', 'status');

        return [
            'semua' => Tab::make('Semua'),
            'gagal' => Tab::make('Gagal')->badge($hitung[LogPesan::GAGAL] ?? null)->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $q) => $q->where('status', LogPesan::GAGAL)),
            'dilewati' => Tab::make('Dilewati')->badge($hitung[LogPesan::DILEWATI] ?? null)->badgeColor('gray')
                ->modifyQueryUsing(fn (Builder $q) => $q->where('status', LogPesan::DILEWATI)),
            'terkirim' => Tab::make('Terkirim')->badge($hitung[LogPesan::TERKIRIM] ?? null)->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $q) => $q->where('status', LogPesan::TERKIRIM)),
        ];
    }

    public function getSubheading(): ?string
    {
        return 'Angka pada tab = 30 hari terakhir. "Dilewati" = sengaja tidak dikirim (tak ada nomor/email, kanal dimatikan, atau server uji).';
    }
}
