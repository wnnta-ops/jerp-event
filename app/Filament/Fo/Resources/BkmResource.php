<?php

namespace App\Filament\Fo\Resources;

use App\Filament\Fo\Resources\BkmResource\Pages;
use App\Modules\FO\FinanceAR\Models\Bkm;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class BkmResource extends Resource
{
    protected static ?string $model = Bkm::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'Finance AR';

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Kwitansi';

    protected static ?string $modelLabel = 'Kwitansi';

    protected static ?string $pluralModelLabel = 'Kwitansi';

    /** Kwitansi terbit otomatis dari alur pembayaran (SPRS/TTS) — tidak boleh dibuat manual. */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Identitas Kwitansi')
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('no_bkm')
                        ->label('No Kwitansi')
                        ->disabled()
                        ->dehydrated()
                        ->placeholder('Otomatis'),
                    Forms\Components\DatePicker::make('tgl_bkm')->label('Tgl Kwitansi')->required()->default(now()),
                    Forms\Components\Select::make('tts_id')
                        ->label('Sumber TTS')
                        ->relationship('tts', 'no_tts')
                        ->searchable()
                        ->preload()
                        ->disabled()
                        ->dehydrated(),
                ]),

            Forms\Components\Section::make('Customer & Kontrak')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('customer_id')
                        ->label('Customer')
                        ->relationship('customer', 'full_name')
                        ->getOptionLabelFromRecordUsing(fn ($record) => $record->code.' — '.$record->full_name)
                        ->searchable(['code', 'full_name', 'nik'])
                        ->preload()
                        ->required(),
                    Forms\Components\Select::make('kontrak_id')
                        ->label('Kontrak')
                        ->relationship('kontrak', 'no_kontrak')
                        ->searchable()
                        ->preload(),
                ]),

            Forms\Components\Section::make('Detail Kas')
                ->columns(3)
                ->schema([
                    Forms\Components\Select::make('jenis_pembayaran')
                        ->label('Jenis')
                        ->options([
                            'BOOKING' => 'Booking fee',
                            'DP' => 'Down Payment',
                            'ANGSURAN' => 'Angsuran',
                            'PELUNASAN' => 'Pelunasan',
                            'LAIN' => 'Lain',
                        ])
                        ->required(),
                    Forms\Components\TextInput::make('amount')
                        ->label('Jumlah')
                        ->numeric()
                        ->rupiah()
                        ->required()
                        ->default(0),
                    Forms\Components\Select::make('bank_id')
                        ->label('Bank')
                        ->relationship('bank', 'name')
                        ->getOptionLabelFromRecordUsing(fn ($record) => $record->code.' — '.$record->name)
                        ->searchable()
                        ->preload(),
                    Forms\Components\TextInput::make('akun_kas')
                        ->label('Akun kas (GL)')
                        ->maxLength(32)
                        ->helperText('Kode akun untuk posting ke GL nanti.'),
                    Forms\Components\TextInput::make('no_referensi')->label('No referensi')->maxLength(64),
                    Forms\Components\Select::make('status')
                        ->label('Status')
                        ->options(['POSTED' => 'Posted', 'BATAL' => 'Batal'])
                        ->default('POSTED')
                        ->required(),
                ]),

            Forms\Components\Section::make('Keterangan')
                ->schema([
                    Forms\Components\Textarea::make('keterangan')->rows(2),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('no_bkm')->label('No Kwitansi')->badge()->color('success')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('tgl_bkm')->label('Tgl')->date('d M Y')->sortable(),
                Tables\Columns\TextColumn::make('tts.no_tts')->label('Sumber TTS')->placeholder('Manual')->toggleable(),
                Tables\Columns\TextColumn::make('customer.full_name')->label('Customer')->searchable(),
                Tables\Columns\TextColumn::make('kontrak.no_kontrak')->label('Kontrak')->toggleable(),
                Tables\Columns\TextColumn::make('jenis_pembayaran')->label('Jenis')->badge(),
                Tables\Columns\TextColumn::make('amount')->label('Jumlah')->money('IDR', divideBy: 0)->alignEnd(),
                Tables\Columns\TextColumn::make('bank.code')->label('Bank')->toggleable(),
                Tables\Columns\TextColumn::make('status')->label('Status')->badge()->color(fn (?string $state) => match ($state) {
                    'POSTED' => 'success',
                    'BATAL' => 'danger',
                    default => 'gray',
                }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(['POSTED' => 'Posted', 'BATAL' => 'Batal']),
                Tables\Filters\SelectFilter::make('bank_id')->relationship('bank', 'name')->label('Bank'),
                Tables\Filters\Filter::make('tgl_bkm')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('Dari'),
                        Forms\Components\DatePicker::make('until')->label('Sampai'),
                    ])
                    ->query(function ($query, array $data) {
                        return $query
                            ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('tgl_bkm', '>=', $d))
                            ->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('tgl_bkm', '<=', $d));
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('cetak_kwitansi')->label('Cetak Kwitansi')->icon('heroicon-o-printer')->color('success')
                    ->url(fn ($record) => route('fo.cetak.kwitansi', $record), shouldOpenInNewTab: true),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('tgl_bkm', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBkms::route('/'),
            'create' => Pages\CreateBkm::route('/create'),
            'edit' => Pages\EditBkm::route('/{record}/edit'),
        ];
    }
}
