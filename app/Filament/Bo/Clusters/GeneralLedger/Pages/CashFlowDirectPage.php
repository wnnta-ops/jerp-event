<?php

namespace App\Filament\Bo\Clusters\GeneralLedger\Pages;

use App\Filament\Bo\Clusters\GeneralLedger\Pages\Concerns\GlPlaceholderPage;

class CashFlowDirectPage extends GlPlaceholderPage
{
    protected static ?string $navigationGroup = 'Cash Flow Direct';

    protected static ?string $navigationLabel = 'Cash Flow Direct | Multi Periode';

    protected static ?string $title = 'Cash Flow Direct | Multi Periode';

    protected static ?int $navigationSort = 1;

    protected static string $keterangan = 'Laporan arus kas metode langsung.';
}
