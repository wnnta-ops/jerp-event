<?php

namespace App\Filament\Bo\Clusters;

use Filament\Clusters\Cluster;

/**
 * General Ledger cluster — mirrors the legacy eProperty GL module which has its
 * own left-hand menu (Setup, Master, Query, Neraca, Laba Rugi, Cash Flow,
 * Saldo Awal, Jurnal Memorial, Generate Laporan). Each menu item is a clustered
 * Resource/Page assigned via `protected static ?string $cluster = GeneralLedger::class`.
 */
class GeneralLedger extends Cluster
{
    protected static ?string $navigationIcon = 'heroicon-o-book-open';

    protected static ?string $navigationLabel = 'General Ledger';

    protected static ?string $clusterBreadcrumb = 'General Ledger';

    protected static ?int $navigationSort = 1;
}
