<?php

namespace App\Filament\Bo\Clusters\GeneralLedger\Pages;

use App\Filament\Bo\Clusters\GeneralLedger\Pages\Concerns\GlPlaceholderPage;

class LabaRugiPerbandinganPage extends GlPlaceholderPage
{
    protected static ?string $navigationGroup = 'Laba Rugi';

    protected static ?string $navigationLabel = 'Laba Rugi Perbandingan';

    protected static ?string $title = 'Laba Rugi Perbandingan';

    protected static ?int $navigationSort = 4;

    protected static string $keterangan = 'Laba rugi perbandingan (anggaran vs realisasi).';
}
