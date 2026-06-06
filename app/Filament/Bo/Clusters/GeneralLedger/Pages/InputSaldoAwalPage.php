<?php

namespace App\Filament\Bo\Clusters\GeneralLedger\Pages;

use App\Filament\Bo\Clusters\GeneralLedger\Pages\Concerns\GlPlaceholderPage;

class InputSaldoAwalPage extends GlPlaceholderPage
{
    protected static ?string $navigationGroup = 'Saldo Awal';

    protected static ?string $navigationLabel = 'Input Saldo Awal';

    protected static ?string $title = 'Input Saldo Awal';

    protected static ?int $navigationSort = 1;

    protected static string $keterangan = 'Input saldo awal akun per periode pembukaan.';
}
