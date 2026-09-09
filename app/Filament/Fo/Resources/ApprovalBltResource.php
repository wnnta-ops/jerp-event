<?php

namespace App\Filament\Fo\Resources;

use App\Filament\Concerns\MematuhiIzin;
use App\Filament\Fo\Resources\ApprovalBltResource\Pages;

/**
 * Approval BLT — approval untuk pengajuan BLT (jenis = BLT). Disetujui (L2) →
 * BLT batch dicairkan (PAID) & masuk Realisasi BLT. Ditolak → BLT kembali DRAFT.
 */
class ApprovalBltResource extends ApprovalResource
{
    use MematuhiIzin;

    protected static ?string $jenisScope = 'BLT';

    protected static ?string $slug = 'approval-blt';

    protected static ?string $navigationLabel = 'Approval BLT';

    protected static ?string $modelLabel = 'Approval BLT';

    protected static ?string $pluralModelLabel = 'Approval BLT';

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListApprovalBlts::route('/'),
        ];
    }
}
