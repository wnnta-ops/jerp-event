<?php

namespace App\Filament\Fo\Resources;

use App\Filament\Fo\Resources\ReservasiResource\Pages;
use App\Modules\FO\MarketingSales\Models\Reservasi;
use App\Modules\FO\MarketingSales\Models\Unit;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ReservasiResource extends Resource
{
    protected static ?string $model = Reservasi::class;

    protected static ?string $navigationIcon = 'heroicon-o-bookmark';

    protected static ?string $navigationGroup = 'Marketing & Sales';

    protected static ?int $navigationSort = 30;

    protected static ?string $modelLabel = 'Reservasi';

    protected static ?string $pluralModelLabel = 'Reservasi';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Reservasi')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('no_reservasi')
                        ->label('No reservasi')
                        ->maxLength(32)
                        ->placeholder('Otomatis')
                        ->disabled()
                        ->dehydrated()
                        ->helperText('Di-generate otomatis saat simpan.'),
                    Forms\Components\DatePicker::make('tgl_reservasi')
                        ->label('Tanggal reservasi')
                        ->required()
                        ->default(now()),
                    Forms\Components\Select::make('customer_id')
                        ->label('Customer')
                        ->relationship('customer', 'full_name')
                        ->getOptionLabelFromRecordUsing(fn ($record) => $record->code.' — '.$record->full_name)
                        ->searchable(['code', 'full_name', 'nik'])
                        ->preload()
                        ->required(),
                    Forms\Components\Select::make('unit_id')
                        ->label('Unit')
                        ->relationship('unit', 'no_unit', fn ($query) => $query->where('status', 'TERSEDIA'))
                        ->getOptionLabelFromRecordUsing(fn ($record) => trim(($record->blok ? $record->blok.' / ' : '').$record->no_unit))
                        ->searchable(['blok', 'no_unit'])
                        ->preload()
                        ->required()
                        ->live()
                        ->afterStateUpdated(function ($state, callable $set) {
                            $unit = Unit::find($state);
                            if ($unit) {
                                $set('harga_jual', $unit->harga_jual);
                            }
                        }),
                    Forms\Components\TextInput::make('harga_jual')->label('Harga jual')->numeric()->rupiah(),
                    Forms\Components\TextInput::make('booking_fee')->label('Booking fee')->numeric()->rupiah()->default(0),
                    Forms\Components\DatePicker::make('tgl_kadaluarsa')
                        ->label('Tanggal kadaluarsa')
                        ->helperText('Tanggal batas reservasi sebelum batal otomatis.'),
                    Forms\Components\Select::make('status')
                        ->label('Status')
                        ->options([
                            'AKTIF' => 'Aktif',
                            'KADALUARSA' => 'Kadaluarsa',
                            'BATAL' => 'Batal',
                            'NAIK_KONTRAK' => 'Naik kontrak',
                        ])
                        ->default('AKTIF')
                        ->required(),
                    Forms\Components\Textarea::make('keterangan')->rows(2)->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('no_reservasi')->label('No reservasi')->searchable()->sortable()->badge(),
                Tables\Columns\TextColumn::make('tgl_reservasi')->label('Tanggal')->date('d M Y')->sortable(),
                Tables\Columns\TextColumn::make('customer.full_name')->label('Customer')->searchable(),
                Tables\Columns\TextColumn::make('unit.no_unit')
                    ->label('Unit')
                    ->formatStateUsing(fn ($record) => trim(($record->unit?->blok ? $record->unit->blok.' / ' : '').$record->unit?->no_unit))
                    ->searchable(),
                Tables\Columns\TextColumn::make('harga_jual')->label('Harga')->money('IDR', divideBy: 0)->alignEnd(),
                Tables\Columns\TextColumn::make('booking_fee')->label('Booking fee')->money('IDR', divideBy: 0)->alignEnd()->toggleable(),
                Tables\Columns\TextColumn::make('tgl_kadaluarsa')->label('Kadaluarsa')->date('d M Y')->toggleable(),
                Tables\Columns\TextColumn::make('status')->label('Status')->badge()->color(fn (?string $state) => match ($state) {
                    'AKTIF' => 'success',
                    'KADALUARSA' => 'warning',
                    'BATAL' => 'danger',
                    'NAIK_KONTRAK' => 'primary',
                    default => 'gray',
                }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options([
                    'AKTIF' => 'Aktif', 'KADALUARSA' => 'Kadaluarsa', 'BATAL' => 'Batal', 'NAIK_KONTRAK' => 'Naik kontrak',
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
            ->defaultSort('tgl_reservasi', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListReservasis::route('/'),
            'create' => Pages\CreateReservasi::route('/create'),
            'edit' => Pages\EditReservasi::route('/{record}/edit'),
        ];
    }
}
