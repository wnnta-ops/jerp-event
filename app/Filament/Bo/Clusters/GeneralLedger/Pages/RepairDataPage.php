<?php

namespace App\Filament\Bo\Clusters\GeneralLedger\Pages;

use App\Filament\Bo\Clusters\GeneralLedger\Pages\Concerns\GlPlaceholderPage;

class RepairDataPage extends GlPlaceholderPage
{
    protected static ?string $navigationGroup = 'Setup';

    protected static ?string $navigationLabel = 'Repair Data';

    protected static ?string $title = 'Repair Data';

    protected static ?int $navigationSort = 4;

    protected static string $keterangan = 'Utilitas perbaikan/rekalkulasi data GL.';
}
