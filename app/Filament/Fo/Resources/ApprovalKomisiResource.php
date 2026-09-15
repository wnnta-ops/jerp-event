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

    /**
     * Approval Komisi mengajukan SEKUMPULAN Komisi sekaligus, bukan satu kontrak —
     * karena itu No. SPR, Nama User, dan No. Unit selalu kosong di menu ini.
     * Diganti pengenal yang memang dimiliki baris approval-nya sendiri.
     *
     * @return array<int,\Filament\Tables\Columns\TextColumn>
     */
    protected static function kolomIdentitas(): array
    {
        return [
            \Filament\Tables\Columns\TextColumn::make('no_approval')->label('No. Approval Komisi')
                ->badge()->color('primary')->searchable()->placeholder('—'),

            \Filament\Tables\Columns\TextColumn::make('tgl_approval')->label('Tanggal Pengajuan')
                ->date('d M Y')->sortable()->placeholder('—'),

            \Filament\Tables\Columns\TextColumn::make('amount_dampak')->label('Komisi NETT')
                ->money('IDR')->alignRight()->sortable()->placeholder('—'),
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListApprovalKomisis::route('/'),
        ];
    }
}
