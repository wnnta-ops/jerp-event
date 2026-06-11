<?php

namespace App\Filament\Fo\Resources;

use App\Filament\Fo\Resources\ApprovalAdjustmentResource\Pages;

class ApprovalAdjustmentResource extends ApprovalResource
{
    protected static ?string $jenisScope = 'ADJ_HARGA';

    protected static ?string $slug = 'approval-adjustment';

    protected static ?string $navigationLabel = 'Approval Adjustment';

    protected static ?string $modelLabel = 'Approval Adjustment';

    protected static ?string $pluralModelLabel = 'Approval Adjustment';

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListApprovalAdjustments::route('/'),
        ];
    }
}
