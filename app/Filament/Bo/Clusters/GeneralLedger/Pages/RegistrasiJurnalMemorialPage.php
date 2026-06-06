<?php

namespace App\Filament\Bo\Clusters\GeneralLedger\Pages;

use App\Filament\Bo\Clusters\GeneralLedger\Pages\Concerns\GlPlaceholderPage;

class RegistrasiJurnalMemorialPage extends GlPlaceholderPage
{
    protected static ?string $navigationGroup = 'Jurnal Memorial';

    protected static ?string $navigationLabel = 'Registrasi Jurnal Memorial';

    protected static ?string $title = 'Registrasi Jurnal Memorial';

    protected static ?int $navigationSort = 1;

    protected static string $keterangan = 'Registrasi jurnal memorial.';
}
