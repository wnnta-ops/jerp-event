<?php

namespace App\Filament\Fo\Resources;

use App\Filament\Fo\Resources\ApprovalPengalihanHakResource\Pages;

class ApprovalPengalihanHakResource extends ApprovalResource
{
    protected static ?string $jenisScope = 'GANTI_NAMA';

    protected static ?string $slug = 'approval-pengalihan-hak';

    protected static ?string $navigationLabel = 'Approval Pengalihan Hak';

    protected static ?string $modelLabel = 'Approval Pengalihan Hak';

    protected static ?string $pluralModelLabel = 'Approval Pengalihan Hak';

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListApprovalPengalihanHaks::route('/'),
        ];
    }
}
