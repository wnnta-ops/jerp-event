<?php

namespace App\Filament\Bo\Clusters\GeneralLedger\Pages;

use App\Filament\Bo\Clusters\GeneralLedger\Pages\Concerns\GlPlaceholderPage;

class NeracaKomparasiTahunanPage extends GlPlaceholderPage
{
    protected static ?string $navigationGroup = 'Neraca';

    protected static ?string $navigationLabel = 'Neraca Komparasi (Tahunan)';

    protected static ?string $title = 'Neraca Komparasi (Tahunan)';

    protected static ?int $navigationSort = 6;

    protected static string $keterangan = 'Perbandingan neraca antar tahun.';
}
