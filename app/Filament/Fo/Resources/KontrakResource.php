<?php

namespace App\Filament\Fo\Resources;

use App\Filament\Fo\Resources\KontrakResource\Pages;
use App\Modules\FO\MarketingSales\Models\Kontrak;
use App\Modules\FO\MarketingSales\Models\Reservasi;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class KontrakResource extends Resource
{
    protected static ?string $model = Kontrak::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationGroup = 'Marketing & Sales';

    protected static ?int $navigationSort = 40;

    protected static ?string $modelLabel = 'Kontrak';

    protected static ?string $pluralModelLabel = 'Kontrak';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Identitas Kontrak')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('no_kontrak')
                        ->label('No kontrak')
                        ->maxLength(32)
                        ->placeholder('Otomatis')
                        ->disabled()
                        ->dehydrated()
                        ->helperText('Di-generate otomatis saat simpan.'),
                    Forms\Components\DatePicker::make('tgl_kontrak')->label('Tanggal kontrak')->required()->default(now()),
                    Forms\Components\Select::make('reservasi_id')
                        ->label('Reservasi (opsional)')
                        ->relationship('reservasi', 'no_reservasi')
                        ->searchable()
                        ->preload()
                        ->live()
                        ->afterStateUpdated(function ($state, callable $set) {
                            $r = Reservasi::find($state);
                            if ($r) {
                                $set('customer_id', $r->customer_id);
                                $set('unit_id', $r->unit_id);
                                $set('harga_jual', $r->harga_jual);
                            }
                        }),
                    Forms\Components\Select::make('customer_id')
                        ->label('Customer')
                        ->relationship('customer', 'full_name')
                        ->getOptionLabelFromRecordUsing(fn ($record) => $record->code.' — '.$record->full_name)
                        ->searchable(['code', 'full_name'])
                        ->preload()
                        ->required(),
                    Forms\Components\Select::make('unit_id')
                        ->label('Unit')
                        ->relationship('unit', 'no_unit')
                        ->getOptionLabelFromRecordUsing(fn ($record) => trim(($record->blok ? $record->blok.' / ' : '').$record->no_unit))
                        ->searchable(['blok', 'no_unit'])
                        ->preload()
                        ->required(),
                ]),

            Forms\Components\Section::make('Harga')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('harga_jual')->label('Harga jual')->numeric()->prefix('Rp')->default(0)->required(),
                    Forms\Components\TextInput::make('diskon')->label('Diskon')->numeric()->prefix('Rp')->default(0),
                    Forms\Components\TextInput::make('ppn')->label('PPN')->numeric()->prefix('Rp')->default(0),
                    Forms\Components\TextInput::make('total_kontrak')->label('Total kontrak')->numeric()->prefix('Rp')->default(0)->required(),
                ]),

            Forms\Components\Section::make('Cara Bayar')
                ->columns(3)
                ->schema([
                    Forms\Components\Select::make('cara_bayar')
                        ->label('Cara bayar')
                        ->options([
                            'CASH' => 'Cash',
                            'CASH_BERTAHAP' => 'Cash bertahap',
                            'CICILAN' => 'Cicilan internal',
                            'KPR' => 'KPR',
                        ])
                        ->default('KPR')
                        ->required(),
                    Forms\Components\TextInput::make('tenor_bulan')->label('Tenor (bulan)')->numeric(),
                    Forms\Components\TextInput::make('dp_persen')->label('DP %')->numeric()->suffix('%'),
                    Forms\Components\TextInput::make('dp_amount')->label('DP (Rp)')->numeric()->prefix('Rp'),
                ]),

            Forms\Components\Section::make('Status')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('status')
                        ->label('Status')
                        ->options([
                            'DRAFT' => 'Draft',
                            'BOOKING' => 'Booking',
                            'AKTIF' => 'Aktif',
                            'LUNAS' => 'Lunas',
                            'BATAL' => 'Batal',
                        ])
                        ->default('DRAFT')
                        ->required(),
                    Forms\Components\Textarea::make('keterangan')->rows(2)->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('no_kontrak')->label('No kontrak')->searchable()->sortable()->badge(),
                Tables\Columns\TextColumn::make('tgl_kontrak')->label('Tgl')->date('d M Y')->sortable(),
                Tables\Columns\TextColumn::make('customer.full_name')->label('Customer')->searchable(),
                Tables\Columns\TextColumn::make('unit.no_unit')
                    ->label('Unit')
                    ->formatStateUsing(fn ($record) => trim(($record->unit?->blok ? $record->unit->blok.' / ' : '').$record->unit?->no_unit))
                    ->searchable(),
                Tables\Columns\TextColumn::make('cara_bayar')->label('Cara bayar')->badge(),
                Tables\Columns\TextColumn::make('total_kontrak')->label('Total')->money('IDR', divideBy: 0)->alignEnd(),
                Tables\Columns\TextColumn::make('status')->label('Status')->badge()->color(fn (?string $state) => match ($state) {
                    'DRAFT' => 'gray',
                    'BOOKING' => 'info',
                    'AKTIF' => 'success',
                    'LUNAS' => 'primary',
                    'BATAL' => 'danger',
                    default => 'gray',
                }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('cara_bayar')->options([
                    'CASH' => 'Cash', 'CASH_BERTAHAP' => 'Cash bertahap', 'CICILAN' => 'Cicilan internal', 'KPR' => 'KPR',
                ]),
                Tables\Filters\SelectFilter::make('status')->options([
                    'DRAFT' => 'Draft', 'BOOKING' => 'Booking', 'AKTIF' => 'Aktif', 'LUNAS' => 'Lunas', 'BATAL' => 'Batal',
                ]),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('cetak_spr')->label('Cetak SPR')->icon('heroicon-o-document-text')
                    ->url(fn ($record) => route('fo.cetak.spr', $record), shouldOpenInNewTab: true),
                Tables\Actions\Action::make('cetak_jadwal')->label('Jadwal')->icon('heroicon-o-calendar-days')
                    ->url(fn ($record) => route('fo.cetak.jadwal', $record), shouldOpenInNewTab: true),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('tgl_kontrak', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListKontraks::route('/'),
            'create' => Pages\CreateKontrak::route('/create'),
            'edit' => Pages\EditKontrak::route('/{record}/edit'),
        ];
    }
}
