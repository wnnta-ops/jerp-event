<?php

namespace App\Filament\Bo\Clusters\GeneralLedger\Pages;

use App\Filament\Bo\Clusters\GeneralLedger\Pages\Concerns\GlPlaceholderPage;

class LabaRugiKomparasiBulananPage extends GlPlaceholderPage
{
    protected static ?string $navigationGroup = 'Laba Rugi';

    protected static ?string $navigationLabel = 'Laba Rugi Komparasi (Bulanan)';

    protected static ?string $title = 'Laba Rugi Komparasi (Bulanan)';

    protected static ?int $navigationSort = 2;

    protected static string $keterangan = 'Perbandingan laba rugi antar bulan.';
}
