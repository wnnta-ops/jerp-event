<?php

namespace App\Filament\Bo\Clusters\GeneralLedger\Pages;

use App\Filament\Bo\Clusters\GeneralLedger\Pages\Concerns\GlPlaceholderPage;

class TransaksiJurnalUmumPage extends GlPlaceholderPage
{
    protected static ?string $navigationGroup = 'Query';

    protected static ?string $navigationLabel = 'Transaksi Jurnal Umum';

    protected static ?string $title = 'Transaksi Jurnal Umum';

    protected static ?int $navigationSort = 2;

    protected static string $keterangan = 'Telusuri jurnal umum (JU).';
}
