<?php

namespace App\Filament\Bo\Widgets;

use App\Models\Company;
use App\Modules\BO\AccountsPayable\Services\ApAgingService;
use App\Modules\BO\AccountsReceivable\Services\ArAgingService;
use App\Modules\BO\GeneralLedger\Services\FinancialReportService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class RingkasanKeuanganWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $companyId = Company::query()->value('id');
        $reports = app(FinancialReportService::class);

        $lr = $reports->labaRugi($companyId);
        $tbTotals = $reports->trialBalanceTotals($companyId);
        $arOutstanding = app(ArAgingService::class)->totalOutstanding($companyId);
        $apOutstanding = app(ApAgingService::class)->totalOutstanding($companyId);

        $fmt = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.');

        return [
            Stat::make('Laba Berjalan', $fmt($lr['laba']))
                ->description($lr['laba'] >= 0 ? 'Profit' : 'Rugi')
                ->color($lr['laba'] >= 0 ? 'success' : 'danger')
                ->icon('heroicon-o-arrow-trending-up'),
            Stat::make('Piutang Outstanding (AR)', $fmt($arOutstanding))
                ->description('Belum tertagih')->color('warning')->icon('heroicon-o-document-currency-dollar'),
            Stat::make('Hutang Outstanding (AP)', $fmt($apOutstanding))
                ->description('Belum dibayar')->color('info')->icon('heroicon-o-document-text'),
            Stat::make('Neraca Saldo', $tbTotals['seimbang'] ? 'SEIMBANG ✓' : 'TIDAK SEIMBANG ✗')
                ->description($fmt($tbTotals['total_debet']))
                ->color($tbTotals['seimbang'] ? 'success' : 'danger')->icon('heroicon-o-scale'),
        ];
    }
}
