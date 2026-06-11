<?php

namespace App\Filament\Fo\Resources;

use App\Filament\Fo\Resources\ApprovalDiskonResource\Pages;

class ApprovalDiskonResource extends ApprovalResource
{
    protected static ?string $jenisScope = 'DISKON';

    protected static ?string $slug = 'approval-diskon';

    protected static ?string $navigationLabel = 'Approval Diskon';

    protected static ?string $modelLabel = 'Approval Diskon';

    protected static ?string $pluralModelLabel = 'Approval Diskon';

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListApprovalDiskons::route('/'),
        ];
    }
}
