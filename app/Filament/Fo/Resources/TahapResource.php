<?php

namespace App\Filament\Fo\Resources;

use App\Filament\Concerns\MematuhiIzin;
use App\Filament\Fo\Resources\TahapResource\Pages;
use App\Modules\FO\MarketingSales\Models\Tahap;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Setup Unit → master Tahap (per Lokasi). Kode jadi segmen kedua No. Unit.
 */
class TahapResource extends Resource
{
    use MematuhiIzin;

    protected static ?string $model = Tahap::class;

    protected static ?string $navigationIcon = 'heroicon-o-square-3-stack-3d';

    protected static ?string $navigationGroup = 'Setup Unit';

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Tahap';

    protected static ?string $modelLabel = 'Tahap';

    protected static ?string $pluralModelLabel = 'Setup Unit — Tahap';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Tahap')->columns(2)->schema([
                Forms\Components\Select::make('lokasi_id')->label('Lokasi')
                    ->relationship('lokasi', 'nama')
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->kode.' — '.$record->nama)
                    ->required()->searchable()->preload(),
                Forms\Components\TextInput::make('kode')->label('Kode Tahap')->required()->maxLength(32)
                    ->placeholder('mis. 004')
                    ->helperText('Dipakai sebagai segmen kedua No. Unit (Lokasi-Tahap-Blok-Nomer).'),
                Forms\Components\TextInput::make('nama')->label('Nama Tahap')->required()->maxLength(255)
                    ->placeholder('mis. Tahap 4'),
                Forms\Components\Toggle::make('is_active')->label('Aktif')->default(true)->inline(false),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('lokasi.project.name')->label('Project')->badge()->toggleable(),
                Tables\Columns\TextColumn::make('lokasi.kode')->label('Lokasi')->badge()->color('info')->sortable()->searchable(),
                Tables\Columns\TextColumn::make('kode')->label('Kode')->badge()->sortable()->searchable()->copyable(),
                Tables\Columns\TextColumn::make('nama')->label('Nama')->searchable(),
                Tables\Columns\TextColumn::make('units_count')->counts('units')->label('Unit')->alignEnd(),
                Tables\Columns\IconColumn::make('is_active')->label('Aktif')->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('lokasi_id')->relationship('lokasi', 'nama')->label('Lokasi')->searchable(),
                Tables\Filters\TernaryFilter::make('is_active')->label('Aktif'),
            ])
            ->actions([Tables\Actions\EditAction::make()])
            ->bulkActions([Tables\Actions\BulkActionGroup::make([Tables\Actions\DeleteBulkAction::make()])])
            ->defaultSort('kode');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTahap::route('/'),
            'create' => Pages\CreateTahap::route('/create'),
            'edit' => Pages\EditTahap::route('/{record}/edit'),
        ];
    }
}
