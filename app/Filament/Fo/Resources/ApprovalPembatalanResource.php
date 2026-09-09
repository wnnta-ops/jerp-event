<?php

namespace App\Filament\Fo\Resources;

use App\Filament\Concerns\MematuhiIzin;
use App\Filament\Fo\Resources\ApprovalPembatalanResource\Pages;

class ApprovalPembatalanResource extends ApprovalResource
{
    use MematuhiIzin;

    protected static ?string $jenisScope = 'BATAL_KONTRAK';

    protected static ?string $slug = 'approval-pembatalan';

    protected static ?string $navigationLabel = 'Approval Pembatalan';

    protected static ?string $modelLabel = 'Approval Pembatalan';

    protected static ?string $pluralModelLabel = 'Approval Pembatalan';

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListApprovalPembatalans::route('/'),
        ];
    }
}
