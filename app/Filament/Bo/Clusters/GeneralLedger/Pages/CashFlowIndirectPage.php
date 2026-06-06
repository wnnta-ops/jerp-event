<?php

namespace App\Filament\Bo\Clusters\GeneralLedger\Pages;

use App\Filament\Bo\Clusters\GeneralLedger\Pages\Concerns\GlPlaceholderPage;

class CashFlowIndirectPage extends GlPlaceholderPage
{
    protected static ?string $navigationGroup = 'Cash Flow Indirect';

    protected static ?string $navigationLabel = 'Cash Flow Indirect | Multi Periode';

    protected static ?string $title = 'Cash Flow Indirect | Multi Periode';

    protected static ?int $navigationSort = 1;

    protected static string $keterangan = 'Laporan arus kas metode tidak langsung.';
}
