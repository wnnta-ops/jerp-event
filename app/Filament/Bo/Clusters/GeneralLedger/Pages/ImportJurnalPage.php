<?php

namespace App\Filament\Bo\Clusters\GeneralLedger\Pages;

use App\Filament\Bo\Clusters\GeneralLedger\Pages\Concerns\GlPlaceholderPage;

class ImportJurnalPage extends GlPlaceholderPage
{
    protected static ?string $navigationGroup = 'Jurnal Memorial';

    protected static ?string $navigationLabel = 'Import Jurnal';

    protected static ?string $title = 'Import Jurnal';

    protected static ?int $navigationSort = 2;

    protected static string $keterangan = 'Impor jurnal dari berkas (Excel/CSV).';
}
