<?php

namespace App\Filament\Bo\Clusters\GeneralLedger\Pages;

use App\Filament\Bo\Clusters\GeneralLedger\Pages\Concerns\GlPlaceholderPage;

class GeneralLedgerQueryPage extends GlPlaceholderPage
{
    protected static ?string $navigationGroup = 'Query';

    protected static ?string $navigationLabel = 'General Ledger';

    protected static ?string $title = 'General Ledger';

    protected static ?int $navigationSort = 3;

    protected static string $keterangan = 'Buku besar per akun (mutasi & saldo berjalan).';
}
