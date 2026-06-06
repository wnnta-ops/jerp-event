<?php

namespace App\Filament\Bo\Clusters\GeneralLedger\Pages;

use App\Filament\Bo\Clusters\GeneralLedger\Pages\Concerns\GlPlaceholderPage;

class MappingCashFlowDirectPage extends GlPlaceholderPage
{
    protected static ?string $navigationGroup = 'Master';

    protected static ?string $navigationLabel = 'Mapping Cash Flow Direct';

    protected static ?string $title = 'Mapping Cash Flow Direct';

    protected static ?int $navigationSort = 4;

    protected static string $keterangan = 'Pemetaan akun ke kategori arus kas langsung.';
}
