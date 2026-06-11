<?php

namespace App\Filament\Fo\Resources\PindahUnitResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/** Riwayat Pindah Unit untuk satu kontrak (unit lama → baru, customer saat pindah). */
class HistoryRelationManager extends RelationManager
{
    protected static string $relationship = 'pindahUnit';

    protected static ?string $title = 'Riwayat Pindah Unit';

    protected static ?string $icon = 'heroicon-o-clock';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('no_pindah')->label('No')->badge()->color('primary'),
                Tables\Columns\TextColumn::make('tgl_pindah')->label('Tanggal')->date('d M Y')->sortable(),
                Tables\Columns\TextColumn::make('unitLama.kode_unit')->label('Unit Lama')->color('danger')->placeholder('—'),
                Tables\Columns\TextColumn::make('unitBaru.kode_unit')->label('Unit Baru')->color('success')->weight('bold')->placeholder('—'),
                Tables\Columns\TextColumn::make('customer.full_name')->label('Customer')->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('keterangan')->label('Keterangan')->placeholder('—')->limit(30)->toggleable(),
            ])
            ->defaultSort('id', 'desc')
            ->emptyStateHeading('Belum ada pindah unit')
            ->emptyStateDescription('Kontrak ini belum pernah pindah unit. Gunakan tombol "Pindah Unit" di atas.')
            ->paginated([10, 25]);
    }
}
