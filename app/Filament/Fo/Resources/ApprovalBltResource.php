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

    /**
     * Approval BLT mengajukan SEKUMPULAN BLT sekaligus, bukan satu kontrak —
     * karena itu No. SPR, Nama User, dan No. Unit selalu kosong di menu ini.
     * Diganti pengenal yang memang dimiliki baris approval-nya sendiri.
     *
     * @return array<int,\Filament\Tables\Columns\TextColumn>
     */
    protected static function kolomIdentitas(): array
    {
        return [
            \Filament\Tables\Columns\TextColumn::make('no_approval')->label('No. Approval BLT')
                ->badge()->color('primary')->searchable()->placeholder('—'),

            \Filament\Tables\Columns\TextColumn::make('tgl_approval')->label('Tanggal Pengajuan')
                ->date('d M Y')->sortable()->placeholder('—'),

            \Filament\Tables\Columns\TextColumn::make('amount_dampak')->label('BLT NETT')
                ->money('IDR')->alignRight()->sortable()->placeholder('—'),
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListApprovalBlts::route('/'),
        ];
    }
}
