<?php

namespace App\Filament\Fo\Resources;

use App\Filament\Fo\Resources\NupTipeResource\Pages;
use App\Modules\FO\Launching\Models\NupTipe;
use App\Modules\FO\MarketingSales\Models\Lokasi;
use App\Modules\FO\MarketingSales\Models\Tahap;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Setup NUP — kelola "Jenis Tipe NUP" per project beserta Nilai UTJ.
 * Sumber dropdown "Jenis Tipe NUP" di form pendaftaran publik.
 */
class NupTipeResource extends Resource
{
    protected static ?string $model = NupTipe::class;

    protected static ?string $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?string $navigationGroup = 'Launching & NUP';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Setup NUP';

    protected static ?string $modelLabel = 'Jenis Tipe NUP';

    protected static ?string $pluralModelLabel = 'Setup NUP — Jenis Tipe NUP';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Jenis Tipe NUP')->columns(2)->schema([
                Forms\Components\Select::make('project_id')->label('Project')
                    ->relationship('project', 'name')->required()->searchable()->preload()
                    ->live()->afterStateUpdated(function (callable $set): void {
                        $set('lokasi_id', null);
                        $set('tahap_id', null);
                    }),
                Forms\Components\TextInput::make('nama')->label('Tipe')->required()->maxLength(255)
                    ->placeholder('mis. Reguler / CLUSTER 3 TAHAP 4'),

                // Cluster + Tahap menentukan bank mana (Setup Bank) yang muncul di
                // dropdown "Bank Pilihan" saat pendaftar memilih tipe ini.
                Forms\Components\Select::make('lokasi_id')->label('Cluster')
                    ->options(fn (Get $get) => $get('project_id')
                        ? Lokasi::query()->where('project_id', $get('project_id'))->where('is_active', true)
                            ->orderBy('kode')->pluck('nama', 'id')
                        : [])
                    ->searchable()->preload()->live()
                    ->afterStateUpdated(fn (callable $set) => $set('tahap_id', null))
                    ->placeholder('— Tidak dikaitkan')
                    ->helperText('Penentu daftar Bank KPR. Kosong → semua bank aktif di project ini.'),
                Forms\Components\Select::make('tahap_id')->label('Tahap')
                    ->options(fn (Get $get) => $get('lokasi_id')
                        ? Tahap::query()->where('lokasi_id', $get('lokasi_id'))->where('is_active', true)
                            ->orderBy('kode')->pluck('nama', 'id')
                        : [])
                    ->searchable()->preload()
                    ->placeholder('— Semua tahap di cluster ini'),
                Forms\Components\TextInput::make('nilai_utj')->label('Nilai (UTJ)')
                    ->numeric()->rupiah()->default(0)->required()
                    ->helperText('Nominal UTJ yang ditagihkan ke customer saat pendaftaran.'),
                Forms\Components\Toggle::make('is_active')->label('Aktif')->default(true)->inline(false),
                Forms\Components\TextInput::make('keterangan')->label('Keterangan')->maxLength(255)->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('project.name')->label('Project')->badge()->searchable()->sortable(),
                Tables\Columns\TextColumn::make('nama')->label('Tipe')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('lokasi.nama')->label('Cluster')->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('tahap.nama')->label('Tahap')->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('nilai_utj')->label('Nilai (UTJ)')->money('IDR', divideBy: 0)->alignEnd()->sortable(),
                Tables\Columns\TextColumn::make('keterangan')->label('Keterangan')->placeholder('—')->toggleable(),
                Tables\Columns\IconColumn::make('is_active')->label('Aktif')->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('project_id')->relationship('project', 'name')->label('Project'),
                Tables\Filters\TernaryFilter::make('is_active')->label('Aktif'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([Tables\Actions\DeleteBulkAction::make()]),
            ])
            ->defaultSort('project_id');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListNupTipe::route('/'),
            'create' => Pages\CreateNupTipe::route('/create'),
            'edit' => Pages\EditNupTipe::route('/{record}/edit'),
        ];
    }
}
