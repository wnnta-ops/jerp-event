<?php

namespace App\Filament\Bo\Clusters\GeneralLedger\Pages;

use App\Filament\Bo\Clusters\GeneralLedger\Pages\Concerns\GlPlaceholderPage;

class KategoriCashFlowDirectPage extends GlPlaceholderPage
{
    protected static ?string $navigationGroup = 'Setup';

    protected static ?string $navigationLabel = 'Kategori Cash Flow Direct';

    protected static ?string $title = 'Kategori Cash Flow Direct';

    protected static ?int $navigationSort = 5;

    protected static string $keterangan = 'Master kategori arus kas metode langsung.';
}
