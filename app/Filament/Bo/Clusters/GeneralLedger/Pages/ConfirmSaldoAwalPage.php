<?php

namespace App\Filament\Bo\Clusters\GeneralLedger\Pages;

use App\Filament\Bo\Clusters\GeneralLedger\Pages\Concerns\GlPlaceholderPage;

class ConfirmSaldoAwalPage extends GlPlaceholderPage
{
    protected static ?string $navigationGroup = 'Saldo Awal';

    protected static ?string $navigationLabel = 'Confirm Saldo Awal';

    protected static ?string $title = 'Confirm Saldo Awal';

    protected static ?int $navigationSort = 2;

    protected static string $keterangan = 'Konfirmasi/penguncian saldo awal.';
}
