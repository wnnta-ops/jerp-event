<?php

namespace App\Filament\Fo\Resources;

use App\Filament\Fo\Resources\UnitResource\Pages;
use App\Modules\FO\MarketingSales\Models\Tahap;
use App\Modules\FO\MarketingSales\Models\Unit;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class UnitResource extends Resource
{
    protected static ?string $model = Unit::class;

    protected static ?string $navigationIcon = 'heroicon-o-home-modern';

    protected static ?string $navigationGroup = 'Marketing & Sales';

    protected static ?int $navigationSort = 20;

    protected static ?string $modelLabel = 'Unit';

    protected static ?string $pluralModelLabel = 'Unit (kavling/rumah)';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Identitas Unit')
                ->columns(3)
                ->schema([
                    Forms\Components\Select::make('lokasi_id')
                        ->label('Lokasi')
                        ->relationship('lokasi', 'nama')
                        ->getOptionLabelFromRecordUsing(fn ($record) => $record->kode.' — '.$record->nama)
                        ->required()->searchable()->preload()->live()
                        ->afterStateUpdated(fn (Forms\Set $set) => $set('tahap_id', null))
                        ->helperText('Segmen pertama No. Unit. Atur di Setup Unit → Lokasi.'),
                    Forms\Components\Select::make('tahap_id')
                        ->label('Tahap')
                        ->options(fn (Forms\Get $get) => $get('lokasi_id')
                            ? Tahap::query()
                                ->where('lokasi_id', $get('lokasi_id'))->where('is_active', true)
                                ->orderBy('kode')->pluck('nama', 'id')
                            : [])
                        ->getOptionLabelUsing(fn ($value) => optional(Tahap::find($value))->nama)
                        ->searchable()
                        ->helperText('Segmen kedua No. Unit. Atur di Setup Unit → Tahap.'),
                    Forms\Components\Select::make('unit_type_id')
                        ->label('Tipe')
                        ->relationship('unitType', 'name')
                        ->searchable()
                        ->preload(),
                    Forms\Components\TextInput::make('cluster')->label('Cluster')->maxLength(255),
                    Forms\Components\TextInput::make('blok')->label('Blok')->maxLength(16),
                    Forms\Components\TextInput::make('no_unit')
                        ->label('Nomer')
                        ->required()
                        ->maxLength(32)
                        ->helperText('No. Unit otomatis: Lokasi-Tahap-Blok-Nomer.'),
                    Forms\Components\Placeholder::make('kode_unit_preview')
                        ->label('No. Unit (otomatis)')
                        ->content(fn (?Unit $record) => $record?->kode_unit ?: '— tersimpan otomatis saat disimpan —'),
                    Forms\Components\Select::make('status')
                        ->label('Status')
                        ->options([
                            'TERSEDIA' => 'Tersedia',
                            'BOOKING' => 'Booking',
                            'TERJUAL' => 'Terjual',
                            'LUNAS' => 'Lunas',
                            'HOLD' => 'Hold',
                            'BATAL' => 'Batal',
                        ])
                        ->default('TERSEDIA')
                        ->required(),
                ]),

            Forms\Components\Section::make('Spesifikasi & Harga')
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('luas_tanah')->label('Luas tanah (m²)')->numeric(),
                    Forms\Components\TextInput::make('luas_bangunan')->label('Luas bangunan (m²)')->numeric(),
                    Forms\Components\TextInput::make('harga_jual')->label('Harga jual')->numeric()->rupiah()->default(0),
                    Forms\Components\Textarea::make('keterangan')->rows(2)->columnSpanFull(),
                    Forms\Components\Toggle::make('is_active')->label('Aktif')->default(true),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('project.code')->label('Proyek')->sortable()->badge()->toggleable(),
                Tables\Columns\TextColumn::make('kode_unit')->label('No. Unit')->badge()->color('info')->sortable()->searchable()->copyable(),
                Tables\Columns\TextColumn::make('lokasi.kode')->label('Lokasi')->sortable()->toggleable(),
                Tables\Columns\TextColumn::make('tahap')->label('Tahap')->sortable()->toggleable(),
                Tables\Columns\TextColumn::make('blok')->label('Blok')->sortable()->searchable(),
                Tables\Columns\TextColumn::make('no_unit')->label('Nomer')->sortable()->searchable()->toggleable(),
                Tables\Columns\TextColumn::make('unitType.name')->label('Tipe')->sortable()->toggleable(),
                Tables\Columns\TextColumn::make('cluster')->label('Cluster')->toggleable(),
                Tables\Columns\TextColumn::make('luas_tanah')->label('LT')->numeric(2)->alignEnd()->toggleable(),
                Tables\Columns\TextColumn::make('luas_bangunan')->label('LB')->numeric(2)->alignEnd()->toggleable(),
                Tables\Columns\TextColumn::make('harga_jual')->label('Harga jual')->money('IDR', divideBy: 0)->alignEnd(),
                Tables\Columns\TextColumn::make('status')->label('Status')->badge()->color(fn (?string $state) => match ($state) {
                    'TERSEDIA' => 'success',
                    'BOOKING' => 'info',
                    'TERJUAL' => 'warning',
                    'LUNAS' => 'primary',
                    'HOLD' => 'gray',
                    'BATAL' => 'danger',
                    default => 'gray',
                }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('project_id')->relationship('project', 'name')->label('Proyek'),
                Tables\Filters\SelectFilter::make('status')->options([
                    'TERSEDIA' => 'Tersedia', 'BOOKING' => 'Booking', 'TERJUAL' => 'Terjual',
                    'LUNAS' => 'Lunas', 'HOLD' => 'Hold', 'BATAL' => 'Batal',
                ]),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('blok')
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUnits::route('/'),
            'create' => Pages\CreateUnit::route('/create'),
            'edit' => Pages\EditUnit::route('/{record}/edit'),
        ];
    }
}
