<?php

namespace App\Filament\Fo\Resources;

use App\Filament\Concerns\MematuhiIzin;
use App\Filament\Fo\Resources\ApprovalCustomizeResource\Pages;

class ApprovalCustomizeResource extends ApprovalResource
{
    use MematuhiIzin;

    protected static ?string $jenisScope = 'LAIN';

    protected static ?string $slug = 'approval-customize';

    protected static ?string $navigationLabel = 'Approval Customize';

    protected static ?string $modelLabel = 'Approval Customize';

    protected static ?string $pluralModelLabel = 'Approval Customize';

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListApprovalCustomizes::route('/'),
        ];
    }
}
