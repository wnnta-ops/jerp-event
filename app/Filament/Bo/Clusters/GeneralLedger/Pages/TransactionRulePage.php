<?php

namespace App\Filament\Bo\Clusters\GeneralLedger\Pages;

use App\Filament\Bo\Clusters\GeneralLedger\Pages\Concerns\GlPlaceholderPage;

class TransactionRulePage extends GlPlaceholderPage
{
    protected static ?string $navigationGroup = 'Setup';

    protected static ?string $navigationLabel = 'Transaction Rule';

    protected static ?string $title = 'Transaction Rule';

    protected static ?int $navigationSort = 1;

    protected static string $keterangan = 'Aturan posting otomatis transaksi ke akun GL.';
}
