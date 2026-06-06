<?php

namespace App\Filament\Bo\Clusters\GeneralLedger\Pages;

use App\Filament\Bo\Clusters\GeneralLedger\Pages\Concerns\GlPlaceholderPage;

class NeracaMultiPeriodePage extends GlPlaceholderPage
{
    protected static ?string $navigationGroup = 'Neraca';

    protected static ?string $navigationLabel = 'Neraca | Multi Periode';

    protected static ?string $title = 'Neraca | Multi Periode';

    protected static ?int $navigationSort = 4;

    protected static string $keterangan = 'Neraca beberapa periode berdampingan.';
}
