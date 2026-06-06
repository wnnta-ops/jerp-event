<?php

namespace App\Filament\Bo\Clusters\GeneralLedger\Pages;

use App\Filament\Bo\Clusters\GeneralLedger\Pages\Concerns\GlPlaceholderPage;

class ApprovalJurnalPage extends GlPlaceholderPage
{
    protected static ?string $navigationGroup = 'Generate Laporan Keuangan';

    protected static ?string $navigationLabel = 'Approval Jurnal';

    protected static ?string $title = 'Approval Jurnal';

    protected static ?int $navigationSort = 2;

    protected static string $keterangan = 'Approval jurnal yang sudah final.';
}
