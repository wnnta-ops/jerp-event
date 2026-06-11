<?php

namespace App\Filament\Fo\Resources;

use App\Filament\Fo\Resources\PindahUnitResource\Pages;
use App\Filament\Fo\Resources\PindahUnitResource\RelationManagers\HistoryRelationManager;
use App\Modules\FO\MarketingSales\Models\Kontrak;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Pindah Unit — daftar kontrak yang sudah SPR (final/terjual). Pilih kontrak → halaman
 * tabel stok untuk pilih unit baru. Riwayat pindah unit per-kontrak tampil saat dibuka.
 */
class PindahUnitResource extends Resource
{
    protected static ?string $model = Kontrak::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static ?string $navigationGroup = 'Marketing & Sales';

    protected static ?string $modelLabel = 'Pindah Unit';

    protected static ?string $pluralModelLabel = 'Pindah Unit';

    protected static ?string $slug = 'pindah-unit';

    protected static ?int $navigationSort = 46;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('tahap', 'SPR')->where('status', 'AKTIF');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Data Kontrak (SPR)')->columns(2)->schema([
                Forms\Components\Placeholder::make('no_kontrak')->label('No. Kontrak')
                    ->content(fn (?Kontrak $record) => $record?->no_kontrak ?? '—'),
                Forms\Components\Placeholder::make('unit')->label('Unit Sekarang')
                    ->content(fn (?Kontrak $record) => $record?->unit?->kode_unit ?? '—'),
                Forms\Components\Placeholder::make('customer')->label('Customer')
                    ->content(fn (?Kontrak $record) => $record?->customer?->full_name ?? '—'),
                Forms\Components\Placeholder::make('sales')->label('Sales')
                    ->content(fn (?Kontrak $record) => $record?->agent?->name ?? '—'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('no_kontrak')->label('No. Kontrak')->badge()->color('info')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('unit.kode_unit')->label('Unit')->placeholder('—')->searchable()->weight('bold'),
                Tables\Columns\TextColumn::make('customer.full_name')->label('Customer')->searchable(),
                Tables\Columns\TextColumn::make('agent.name')->label('Sales')->placeholder('—'),
                Tables\Columns\TextColumn::make('tgl_kontrak')->label('Tgl')->date('d M Y')->sortable(),
                Tables\Columns\TextColumn::make('pindah_unit_count')->counts('pindahUnit')->label('Pindah')->badge()
                    ->color(fn ($state) => $state > 0 ? 'warning' : 'gray')->formatStateUsing(fn ($state) => $state.'×'),
            ])
            ->actions([
                Tables\Actions\Action::make('pindah')->label('Pindah Unit')->icon('heroicon-o-arrows-right-left')->color('primary')
                    ->url(fn (Kontrak $record) => '/fo/pindah-unit-pilih?kontrak='.$record->id),
                Tables\Actions\EditAction::make()->label('Riwayat'),
            ])
            ->defaultSort('tgl_kontrak', 'desc');
    }

    public static function getRelations(): array
    {
        return [HistoryRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPindahUnits::route('/'),
            'edit' => Pages\EditPindahUnit::route('/{record}'),
        ];
    }
}
