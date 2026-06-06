<?php

namespace App\Filament\Bo\Clusters\GeneralLedger\Pages;

use App\Filament\Bo\Clusters\GeneralLedger\Pages\Concerns\GlPlaceholderPage;

class FinalisasiJurnalPage extends GlPlaceholderPage
{
    protected static ?string $navigationGroup = 'Generate Laporan Keuangan';

    protected static ?string $navigationLabel = 'Finalisasi Jurnal';

    protected static ?string $title = 'Finalisasi Jurnal';

    protected static ?int $navigationSort = 1;

    protected static string $keterangan = 'Finalisasi jurnal sebelum approval.';
}
