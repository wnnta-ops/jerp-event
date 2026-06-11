<?php

namespace App\Filament\Fo\Resources\PengalihanHakResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/** Riwayat Pengalihan Hak untuk satu kontrak (customer lama → baru). */
class HistoryRelationManager extends RelationManager
{
    protected static string $relationship = 'pengalihanHak';

    protected static ?string $title = 'Riwayat Pengalihan Hak';

    protected static ?string $icon = 'heroicon-o-clock';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('no_pengalihan')->label('No')->badge()->color('primary'),
                Tables\Columns\TextColumn::make('tgl_pengalihan')->label('Tanggal')->date('d M Y')->sortable(),
                Tables\Columns\TextColumn::make('customerLama.full_name')->label('Dari')->color('danger')->placeholder('—'),
                Tables\Columns\TextColumn::make('customerBaru.full_name')->label('Ke')->color('success')->weight('bold')->placeholder('—'),
                Tables\Columns\TextColumn::make('sales_snapshot')->label('Sales')->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('keterangan')->label('Keterangan')->placeholder('—')->limit(30)->toggleable(),
            ])
            ->defaultSort('id', 'desc')
            ->emptyStateHeading('Belum ada pengalihan hak')
            ->emptyStateDescription('Kontrak ini belum pernah dialihkan. Gunakan tombol "Pengalihan Hak" di atas.')
            ->paginated([10, 25]);
    }
}
