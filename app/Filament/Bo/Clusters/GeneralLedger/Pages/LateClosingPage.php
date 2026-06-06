<?php

namespace App\Filament\Bo\Clusters\GeneralLedger\Pages;

use App\Filament\Bo\Clusters\GeneralLedger\Pages\Concerns\GlPlaceholderPage;

class LateClosingPage extends GlPlaceholderPage
{
    protected static ?string $navigationGroup = 'Generate Laporan Keuangan';

    protected static ?string $navigationLabel = 'Late Closing';

    protected static ?string $title = 'Late Closing';

    protected static ?int $navigationSort = 3;

    protected static string $keterangan = 'Penutupan terlambat untuk periode tertentu.';
}
