<?php

namespace App\Filament\Fo\Widgets;

use App\Modules\FO\FinanceAR\Models\Bkm;
use App\Modules\FO\FinanceAR\Models\Tts;
use App\Modules\FO\MarketingSales\Models\Customer;
use App\Modules\FO\MarketingSales\Models\Kontrak;
use App\Modules\FO\MarketingSales\Models\Reservasi;
use App\Modules\FO\MarketingSales\Models\Unit;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class FoStats extends BaseWidget
{
    protected ?string $heading = 'Ringkasan Front Office';

    protected function getStats(): array
    {
        $totalCustomer = Customer::count();
        $unitTersedia = Unit::where('status', 'TERSEDIA')->count();
        $unitTerjual = Unit::whereIn('status', ['TERJUAL', 'LUNAS'])->count();
        $kontrakBulanIni = Kontrak::whereMonth('tgl_kontrak', now()->month)->whereYear('tgl_kontrak', now()->year)->count();
        $reservasiAktif = Reservasi::where('status', 'AKTIF')->count();
        $bkmBulanIni = Bkm::whereMonth('tgl_bkm', now()->month)->whereYear('tgl_bkm', now()->year)->where('status', 'POSTED')->sum('amount');
        $ttsDraft = Tts::whereIn('status', ['DRAFT', 'KONFIRMASI'])->count();

        return [
            Stat::make('Customer', number_format($totalCustomer))
                ->description('Total customer terdaftar')
                ->descriptionIcon('heroicon-m-user-group')
                ->color('primary'),

            Stat::make('Unit tersedia', number_format($unitTersedia))
                ->description($unitTerjual.' unit terjual / lunas')
                ->descriptionIcon('heroicon-m-home-modern')
                ->color($unitTersedia > 0 ? 'success' : 'warning'),

            Stat::make('Kontrak bulan ini', number_format($kontrakBulanIni))
                ->description($reservasiAktif.' reservasi aktif')
                ->descriptionIcon('heroicon-m-document-text')
                ->color('info'),

            Stat::make('Kas masuk bulan ini', 'Rp '.number_format((float) $bkmBulanIni, 0, ',', '.'))
                ->description($ttsDraft.' TTS pending konfirmasi')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('success'),
        ];
    }
}
