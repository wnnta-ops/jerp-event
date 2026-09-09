<?php

namespace App\Filament\Fo\Resources;

use App\Filament\Concerns\MematuhiIzin;
use App\Filament\Fo\Resources\ApprovalPindahUnitResource\Pages;

class ApprovalPindahUnitResource extends ApprovalResource
{
    use MematuhiIzin;

    protected static ?string $jenisScope = 'GANTI_UNIT';

    protected static ?string $slug = 'approval-pindah-unit';

    protected static ?string $navigationLabel = 'Approval Pindah Unit';

    protected static ?string $modelLabel = 'Approval Pindah Unit';

    protected static ?string $pluralModelLabel = 'Approval Pindah Unit';

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListApprovalPindahUnits::route('/'),
        ];
    }
}
