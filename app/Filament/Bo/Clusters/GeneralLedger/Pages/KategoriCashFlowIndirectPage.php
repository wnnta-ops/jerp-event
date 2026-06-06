<?php

namespace App\Filament\Bo\Clusters\GeneralLedger\Pages;

use App\Filament\Bo\Clusters\GeneralLedger\Pages\Concerns\GlPlaceholderPage;

class KategoriCashFlowIndirectPage extends GlPlaceholderPage
{
    protected static ?string $navigationGroup = 'Setup';

    protected static ?string $navigationLabel = 'Kategori Cash Flow Indirect';

    protected static ?string $title = 'Kategori Cash Flow Indirect';

    protected static ?int $navigationSort = 6;

    protected static string $keterangan = 'Master kategori arus kas metode tidak langsung.';
}
