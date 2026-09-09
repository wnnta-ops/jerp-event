<?php

namespace App\Filament\Fo\Resources;

use App\Filament\Concerns\MematuhiIzin;
use App\Filament\Fo\Resources\ApprovalKomisiResource\Pages;

/**
 * Approval Komisi — approval untuk pengajuan komisi (jenis = KOMISI). Disetujui (L2) →
 * komisi batch dicairkan (PAID) & masuk Realisasi Komisi. Ditolak → komisi kembali DRAFT.
 */
class ApprovalKomisiResource extends ApprovalResource
{
    use MematuhiIzin;

    protected static ?string $jenisScope = 'KOMISI';

    protected static ?string $slug = 'approval-komisi';

    protected static ?string $navigationLabel = 'Approval Komisi';

    protected static ?string $modelLabel = 'Approval Komisi';

    protected static ?string $pluralModelLabel = 'Approval Komisi';

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListApprovalKomisis::route('/'),
        ];
    }
}
