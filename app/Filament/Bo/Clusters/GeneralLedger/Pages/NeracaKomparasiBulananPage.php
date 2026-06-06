<?php

namespace App\Filament\Bo\Clusters\GeneralLedger\Pages;

use App\Filament\Bo\Clusters\GeneralLedger\Pages\Concerns\GlPlaceholderPage;

class NeracaKomparasiBulananPage extends GlPlaceholderPage
{
    protected static ?string $navigationGroup = 'Neraca';

    protected static ?string $navigationLabel = 'Neraca Komparasi (Bulanan)';

    protected static ?string $title = 'Neraca Komparasi (Bulanan)';

    protected static ?int $navigationSort = 5;

    protected static string $keterangan = 'Perbandingan neraca antar bulan.';
}
