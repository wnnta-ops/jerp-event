<?php

namespace App\Filament\Fo\Resources;

use App\Filament\Concerns\MematuhiIzin;
use App\Filament\Fo\Resources\BkmResource\Pages;
use App\Modules\FO\FinanceAR\Models\Bkm;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class BkmResource extends Resource
{
    use MematuhiIzin;

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
                Tables\Columns\TextColumn::make('tts.no_tts')->label('Sumber')->placeholder('—')->toggleable()
                    ->getStateUsing(fn ($record) => $record->tts?->no_tts ?: ($record->kontrak_tagihan_id ? 'Pelunasan' : 'Manual')),
                Tables\Columns\TextColumn::make('customer.full_name')->label('Customer')->searchable(),
                Tables\Columns\TextColumn::make('kontrak.unit.kode_unit')->label('Unit')->placeholder('—')->searchable()->toggleable(),
                // Jenis = nama tagihan spesifik bila pembayaran ter-link ke baris tagihan
                // (mis. "DP 1", "ANGSURAN 3"); fallback ke jenis_pembayaran untuk BKM lama.
                Tables\Columns\TextColumn::make('jenis_pembayaran')->label('Jenis')->badge()
                    ->getStateUsing(fn ($record) => $record->kontrakTagihan?->nama_tagihan ?: $record->jenis_pembayaran),
                Tables\Columns\TextColumn::make('amount')->label('Jumlah')->money('IDR', divideBy: 0)->alignEnd(),
                Tables\Columns\TextColumn::make('bank.code')->label('Bank')->toggleable(),
                Tables\Columns\TextColumn::make('status')->label('Status')->badge()->color(fn (?string $state) => match ($state) {
                    'POSTED' => 'success',
                    'BATAL' => 'danger',
                    default => 'gray',
                }),
                Tables\Columns\TextColumn::make('bo_posted_at')->label('Approval BO')->badge()
                    ->getStateUsing(fn (Bkm $record) => $record->bo_posted_at ? 'Terkirim' : 'Belum')
                    ->color(fn (string $state) => $state === 'Terkirim' ? 'success' : 'gray')
                    ->tooltip(fn (Bkm $record) => $record->bo_posted_at ? 'Dikirim ke Approval UTJ & DP: '.$record->bo_posted_at->format('d M Y H:i') : 'Belum dikirim ke BO'),
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
                // Posting BO: kirim Kwitansi ke "Approval UTJ & DP" BO (menunggu approval); GL
                // terbentuk saat BO pilih akun Debit/Kredit & setujui (tak auto-posting di FO).
                Tables\Actions\Action::make('postingBo')->label('Posting BO')->icon('heroicon-o-paper-airplane')->color('primary')
                    ->visible(fn (Bkm $record) => $record->status === 'POSTED' && ! $record->bo_posted_at)
                    ->requiresConfirmation()
                    ->modalHeading('Posting Kwitansi ke BO')
                    ->modalDescription(fn (Bkm $record) => 'Kirim '.$record->no_bkm.' (Rp '.number_format((float) $record->amount, 0, ',', '.').') ke BO "Approval UTJ & DP" (menunggu approval). GL terbentuk saat BO pilih akun & setujui.')
                    ->action(function (Bkm $record) {
                        try {
                            app(\App\Modules\BO\IproBridge\Services\IproMasterSync::class)->pushBkmToApproval($record);
                            $record->update(['bo_posted_at' => now()]);
                            Notification::make()->success()->title('Dikirim ke Approval UTJ & DP BO')
                                ->body($record->no_bkm.' menunggu approval di BO (pilih akun Debit/Kredit → setujui → GL).')->send();
                        } catch (\Throwable $e) {
                            Notification::make()->danger()->title('Gagal kirim ke BO')->body($e->getMessage())->persistent()->send();
                        }
                    }),
                // Batal: status → BATAL & dana kembali ke step sebelumnya (TTS / tagihan).
                Tables\Actions\Action::make('batal')->label('Batal')->icon('heroicon-o-x-circle')->color('danger')
                    ->visible(fn (Bkm $record) => $record->status !== 'BATAL')
                    ->requiresConfirmation()
                    ->modalHeading('Batalkan Kwitansi')
                    ->modalDescription(fn (Bkm $record) => 'Batalkan '.$record->no_bkm.' (Rp '.number_format((float) $record->amount, 0, ',', '.').')? Status jadi BATAL & dana kembali ke '
                        .($record->tts_id ? 'TTS '.($record->tts?->no_tts ?? '') : ($record->kontrak_tagihan_id ? 'tagihan '.($record->kontrakTagihan?->nama_tagihan ?? '') : 'sumbernya')).'.')
                    ->action(function (Bkm $record) {
                        try {
                            app(\App\Modules\FO\FinanceAR\Services\BkmCancelService::class)->cancel($record);
                            Notification::make()->success()->title('Kwitansi dibatalkan')
                                ->body($record->no_bkm.' → BATAL. Dana dikembalikan ke step sebelumnya.')->send();
                        } catch (\Throwable $e) {
                            Notification::make()->danger()->title('Gagal membatalkan')->body($e->getMessage())->persistent()->send();
                        }
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    // Posting BO massal: kirim Kwitansi terpilih ke "Approval UTJ & DP" BO.
                    // Yang sudah terkirim (bo_posted_at terisi) atau bukan POSTED DILEWATI → anti dobel.
                    Tables\Actions\BulkAction::make('postingBoBulk')->label('Posting BO (terpilih)')->icon('heroicon-o-paper-airplane')->color('primary')
                        ->requiresConfirmation()
                        ->modalHeading('Posting Kwitansi terpilih ke BO')
                        ->modalDescription('Kirim Kwitansi terpilih ke BO "Approval UTJ & DP". Yang sudah terkirim atau bukan POSTED dilewati (tak diposting 2 kali).')
                        ->action(function (\Illuminate\Database\Eloquent\Collection $records) {
                            $svc = app(\App\Modules\BO\IproBridge\Services\IproMasterSync::class);
                            $ok = 0;
                            $skip = 0;
                            $err = 0;
                            foreach ($records as $r) {
                                if ($r->status !== 'POSTED' || $r->bo_posted_at) {
                                    $skip++;

                                    continue;
                                }
                                try {
                                    $svc->pushBkmToApproval($r);
                                    $r->update(['bo_posted_at' => now()]);
                                    $ok++;
                                } catch (\Throwable $e) {
                                    $err++;
                                }
                            }
                            $notif = Notification::make()->title('Posting BO selesai')
                                ->body($ok.' terkirim · '.$skip.' dilewati (sudah terkirim / bukan POSTED)'.($err ? ' · '.$err.' gagal' : '').'.')->persistent();
                            ($err ? $notif->warning() : $notif->success())->send();
                        })
                        ->deselectRecordsAfterCompletion(),
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
