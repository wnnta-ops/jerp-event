<?php

namespace App\Filament\Bo\Clusters\GeneralLedger\Pages;

use App\Filament\Bo\Clusters\GeneralLedger\Pages\Concerns\GlPlaceholderPage;

class HistoriSaldoPage extends GlPlaceholderPage
{
    protected static ?string $navigationGroup = 'Neraca';

    protected static ?string $navigationLabel = 'Histori Saldo';

    protected static ?string $title = 'Histori Saldo';

    protected static ?int $navigationSort = 3;

    protected static string $keterangan = 'Histori saldo akun per periode.';
}
