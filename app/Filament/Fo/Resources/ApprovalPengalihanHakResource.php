<?php

namespace App\Filament\Fo\Resources;

use App\Filament\Fo\Resources\ApprovalPengalihanHakResource\Pages;

/**
 * Format tabelnya (No. SPR · Tanggal SPR · Nama User · No. Unit · Status · Detail)
 * sekarang didefinisikan di ApprovalResource sehingga SELURUH menu approval
 * memakai tampilan yang sama — tak perlu override di sini.
 */
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
