<?php

namespace App\Filament\Fo\Resources;

use App\Filament\Fo\Resources\TtsResource\Pages;
use App\Modules\FO\FinanceAR\Models\Tts;
use App\Modules\FO\FinanceAR\Services\TtsToBkmService;
use App\Modules\FO\MarketingSales\Models\Kontrak;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Actions\Action as NotificationAction;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class TtsResource extends Resource
{
    protected static ?string $model = Tts::class;

    protected static ?string $navigationIcon = 'heroicon-o-ticket';

    protected static ?string $navigationGroup = 'Finance AR';

    protected static ?int $navigationSort = 10;

    protected static ?string $modelLabel = 'TTS';

    protected static ?string $pluralModelLabel = 'Tanda Terima Sementara';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Identitas TTS')
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('no_tts')
                        ->label('No TTS')
                        ->disabled()
                        ->dehydrated()
                        ->placeholder('Otomatis'),
                    Forms\Components\DatePicker::make('tgl_tts')->label('Tgl TTS')->required()->default(now()),
                    Forms\Components\DatePicker::make('tgl_terima')->label('Tgl terima')->default(now()),
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
                        ->required()
                        ->live()
                        ->afterStateUpdated(function ($state, callable $set) {
                            // Auto-set kontrak if customer has only one active kontrak
                            if (! $state) {
                                return;
                            }
                            $k = Kontrak::where('customer_id', $state)
                                ->whereIn('status', ['AKTIF', 'BOOKING'])
                                ->orderByDesc('id')
                                ->first();
                            if ($k) {
                                $set('kontrak_id', $k->id);
                            }
                        }),
                    Forms\Components\Select::make('kontrak_id')
                        ->label('Kontrak')
                        ->relationship('kontrak', 'no_kontrak')
                        ->searchable()
                        ->preload(),
                ]),

            Forms\Components\Section::make('Pembayaran')
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
                        ->default('ANGSURAN')
                        ->required(),
                    Forms\Components\TextInput::make('amount')
                        ->label('Jumlah')
                        ->numeric()
                        ->prefix('Rp')
                        ->required()
                        ->default(0),
                    Forms\Components\Select::make('cara_bayar')
                        ->label('Cara bayar')
                        ->options([
                            'CASH' => 'Tunai',
                            'TRANSFER' => 'Transfer',
                            'GIRO' => 'Giro',
                            'CHEQUE' => 'Cek',
                            'VA' => 'Virtual Account',
                            'XENDIT' => 'Xendit',
                        ])
                        ->default('TRANSFER')
                        ->required()
                        ->live(),
                    Forms\Components\Select::make('bank_id')
                        ->label('Bank')
                        ->relationship('bank', 'name')
                        ->getOptionLabelFromRecordUsing(fn ($record) => $record->code.' — '.$record->name)
                        ->searchable()
                        ->preload()
                        ->visible(fn (callable $get) => in_array($get('cara_bayar'), ['TRANSFER', 'GIRO', 'CHEQUE', 'VA'])),
                    Forms\Components\TextInput::make('no_referensi')
                        ->label('No referensi')
                        ->maxLength(64)
                        ->visible(fn (callable $get) => $get('cara_bayar') !== 'CASH'),
                    Forms\Components\DatePicker::make('tgl_efektif')
                        ->label('Tgl efektif')
                        ->visible(fn (callable $get) => in_array($get('cara_bayar'), ['GIRO', 'CHEQUE'])),
                ]),

            Forms\Components\Section::make('Status & Keterangan')
                ->schema([
                    Forms\Components\Select::make('status')
                        ->label('Status')
                        ->options([
                            'DRAFT' => 'Draft',
                            'KONFIRMASI' => 'Konfirmasi',
                            'JADI_BKM' => 'Jadi BKM',
                            'JADI_BGM' => 'Jadi BGM',
                            'BATAL' => 'Batal',
                        ])
                        ->default('DRAFT')
                        ->required()
                        ->disabledOn('edit')
                        ->helperText('Gunakan tombol aksi "Konfirmasi jadi BKM" di list untuk mengubah status.'),
                    Forms\Components\Textarea::make('keterangan')->rows(2),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('no_tts')->label('No TTS')->badge()->searchable()->sortable(),
                Tables\Columns\TextColumn::make('tgl_tts')->label('Tgl')->date('d M Y')->sortable(),
                Tables\Columns\TextColumn::make('customer.full_name')->label('Customer')->searchable(),
                Tables\Columns\TextColumn::make('kontrak.no_kontrak')->label('Kontrak')->toggleable(),
                Tables\Columns\TextColumn::make('jenis_pembayaran')->label('Jenis')->badge(),
                Tables\Columns\TextColumn::make('amount')->label('Jumlah')->money('IDR', divideBy: 0)->alignEnd(),
                Tables\Columns\TextColumn::make('cara_bayar')->label('Cara')->badge()->color('gray'),
                Tables\Columns\TextColumn::make('status')->label('Status')->badge()->color(fn (?string $state) => match ($state) {
                    'DRAFT' => 'gray',
                    'KONFIRMASI' => 'warning',
                    'JADI_BKM' => 'success',
                    'JADI_BGM' => 'success',
                    'BATAL' => 'danger',
                    default => 'gray',
                }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options([
                    'DRAFT' => 'Draft', 'KONFIRMASI' => 'Konfirmasi', 'JADI_BKM' => 'Jadi BKM',
                    'JADI_BGM' => 'Jadi BGM', 'BATAL' => 'Batal',
                ]),
                Tables\Filters\SelectFilter::make('cara_bayar')->options([
                    'CASH' => 'Tunai', 'TRANSFER' => 'Transfer', 'GIRO' => 'Giro',
                    'CHEQUE' => 'Cek', 'VA' => 'VA', 'XENDIT' => 'Xendit',
                ]),
                Tables\Filters\Filter::make('tgl_tts')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('Dari'),
                        Forms\Components\DatePicker::make('until')->label('Sampai'),
                    ])
                    ->query(function ($query, array $data) {
                        return $query
                            ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('tgl_tts', '>=', $d))
                            ->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('tgl_tts', '<=', $d));
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('konfirmasiBkm')
                    ->label('Buka Kuitansi')
                    ->icon('heroicon-o-receipt-percent')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('Buka kuitansi: konversi TTS ini menjadi BKM (Kwitansi resmi) yang siap dicetak. Aksi ini tidak bisa di-undo lewat UI.')
                    ->visible(fn (Tts $r) => in_array($r->status, ['DRAFT', 'KONFIRMASI']) && $r->cara_bayar !== 'GIRO' && $r->cara_bayar !== 'CHEQUE')
                    ->action(function (Tts $r) {
                        $bkm = TtsToBkmService::convert($r);
                        Notification::make()
                            ->title('Kuitansi dibuka — '.$bkm->no_bkm)
                            ->success()
                            ->actions([
                                NotificationAction::make('cetak')->label('Cetak Kwitansi')
                                    ->url(route('fo.cetak.kwitansi', $bkm), shouldOpenInNewTab: true),
                            ])
                            ->send();
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('tgl_tts', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTts::route('/'),
            'create' => Pages\CreateTts::route('/create'),
            'edit' => Pages\EditTts::route('/{record}/edit'),
        ];
    }
}
