<?php

namespace App\Filament\Fo\Resources;

use App\Filament\Fo\Resources\ApprovalDiskonSprsResource\Pages;

/**
 * Approval khusus Diskon saat kontrak masih SPRS — single-level (langsung GM),
 * beda dari Approval Diskon (SPR) yang 2 level. jenis = DISKON_SPRS.
 */
class ApprovalDiskonSprsResource extends ApprovalResource
{
    protected static ?string $jenisScope = 'DISKON_SPRS';

    protected static ?string $slug = 'approval-diskon-sprs';

    protected static ?string $navigationLabel = 'Approval Diskon SPRS';

    protected static ?string $modelLabel = 'Approval Diskon SPRS';

    protected static ?string $pluralModelLabel = 'Approval Diskon SPRS';

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListApprovalDiskonSprs::route('/'),
        ];
    }
}
