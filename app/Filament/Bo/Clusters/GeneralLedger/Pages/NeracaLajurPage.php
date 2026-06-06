<?php

namespace App\Filament\Bo\Clusters\GeneralLedger\Pages;

use App\Filament\Bo\Clusters\GeneralLedger\Pages\Concerns\GlPlaceholderPage;

class NeracaLajurPage extends GlPlaceholderPage
{
    protected static ?string $navigationGroup = 'Neraca';

    protected static ?string $navigationLabel = 'Neraca Lajur';

    protected static ?string $title = 'Neraca Lajur';

    protected static ?int $navigationSort = 2;

    protected static string $keterangan = 'Worksheet neraca lajur (kertas kerja).';
}
