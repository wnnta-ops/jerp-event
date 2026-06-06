<?php

namespace App\Filament\Bo\Clusters\GeneralLedger\Pages;

use App\Filament\Bo\Clusters\GeneralLedger\Pages\Concerns\GlPlaceholderPage;

class KursSistemPage extends GlPlaceholderPage
{
    protected static ?string $navigationGroup = 'Setup';

    protected static ?string $navigationLabel = 'Kurs Sistem';

    protected static ?string $title = 'Kurs Sistem';

    protected static ?int $navigationSort = 3;

    protected static string $keterangan = 'Kurs mata uang per tanggal untuk penjurnalan.';
}
