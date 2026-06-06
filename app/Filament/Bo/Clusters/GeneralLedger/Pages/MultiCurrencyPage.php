<?php

namespace App\Filament\Bo\Clusters\GeneralLedger\Pages;

use App\Filament\Bo\Clusters\GeneralLedger\Pages\Concerns\GlPlaceholderPage;

class MultiCurrencyPage extends GlPlaceholderPage
{
    protected static ?string $navigationGroup = 'Setup';

    protected static ?string $navigationLabel = 'Multi Currency';

    protected static ?string $title = 'Multi Currency';

    protected static ?int $navigationSort = 2;

    protected static string $keterangan = 'Master mata uang asing untuk transaksi multi-currency.';
}
