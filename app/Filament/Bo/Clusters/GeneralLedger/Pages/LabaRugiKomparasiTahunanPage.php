<?php

namespace App\Filament\Bo\Clusters\GeneralLedger\Pages;

use App\Filament\Bo\Clusters\GeneralLedger\Pages\Concerns\GlPlaceholderPage;

class LabaRugiKomparasiTahunanPage extends GlPlaceholderPage
{
    protected static ?string $navigationGroup = 'Laba Rugi';

    protected static ?string $navigationLabel = 'Laba Rugi Komparasi (Tahunan)';

    protected static ?string $title = 'Laba Rugi Komparasi (Tahunan)';

    protected static ?int $navigationSort = 3;

    protected static string $keterangan = 'Perbandingan laba rugi antar tahun.';
}
