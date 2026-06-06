<?php

namespace App\Filament\Bo\Clusters\GeneralLedger\Pages;

use App\Filament\Bo\Clusters\GeneralLedger\Pages\Concerns\GlPlaceholderPage;

class TransaksiJurnalPage extends GlPlaceholderPage
{
    protected static ?string $navigationGroup = 'Query';

    protected static ?string $navigationLabel = 'Transaksi Jurnal';

    protected static ?string $title = 'Transaksi Jurnal';

    protected static ?int $navigationSort = 1;

    protected static string $keterangan = 'Telusuri transaksi jurnal per dokumen.';
}
