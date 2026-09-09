<?php

namespace App\Filament\Fo\Resources;

use App\Filament\Concerns\MematuhiIzin;
use App\Filament\Fo\Resources\ApprovalRescheduleResource\Pages;

class ApprovalRescheduleResource extends ApprovalResource
{
    use MematuhiIzin;

    protected static ?string $jenisScope = 'RESCHEDULE';

    protected static ?string $slug = 'approval-reschedule';

    protected static ?string $navigationLabel = 'Approval Reschedule';

    protected static ?string $modelLabel = 'Approval Reschedule';

    protected static ?string $pluralModelLabel = 'Approval Reschedule';

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListApprovalReschedules::route('/'),
        ];
    }
}
