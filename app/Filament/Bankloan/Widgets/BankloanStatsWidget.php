<?php

namespace App\Filament\Bankloan\Widgets;

use App\Modules\BankLoan\Models\AgreementRealization;
use App\Modules\BankLoan\Models\Contract;
use App\Modules\BankLoan\Models\RetentionSubmission;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class BankloanStatsWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 0;

    protected function getStats(): array
    {
        $total = Contract::count();
        $active = Contract::where('active_status', 'ACTIVE')->count();
        $draft = Contract::whereIn('active_status', ['DRAFT', 'REGISTERED'])->count();
        $raDisbursed = (float) AgreementRealization::where('status', 'POSTED')->sum('disbursed_amount');
        $prPending = RetentionSubmission::whereIn('status', ['SUBMITTED', 'APPROVED'])->count();

        return [
            Stat::make('Kontrak KPR', number_format($total))
                ->description($draft.' dalam proses · '.$active.' akad')
                ->descriptionIcon('heroicon-m-document-check')
                ->color('primary'),
            Stat::make('Akad Aktif', number_format($active))
                ->description('Sudah akad / berjalan')
                ->descriptionIcon('heroicon-m-check-badge')
                ->color('success'),
            Stat::make('Total Cair (RA)', 'Rp '.number_format($raDisbursed, 0, ',', '.'))
                ->description('Realisasi akad POSTED')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('info'),
            Stat::make('Retensi Diproses (PR)', number_format($prPending))
                ->description('Pengajuan retensi berjalan')
                ->descriptionIcon('heroicon-m-lock-closed')
                ->color('warning'),
        ];
    }
}
