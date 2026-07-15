<?php

namespace App\Filament\Fo\Resources;

use App\Filament\Fo\Resources\TransferAnonimResource\Pages;
use App\Models\Bank;
use App\Modules\FO\FinanceAR\Models\Refund;
use App\Modules\FO\FinanceAR\Models\TransferAnonim;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class TransferAnonimResource extends Resource
{
    protected static ?string $model = TransferAnonim::class;

    protected static ?string $navigationIcon = 'heroicon-o-question-mark-circle';

    protected static ?string $navigationGroup = 'Finance AR';

    protected static ?string $modelLabel = 'Transfer Anonim';

    protected static ?string $pluralModelLabel = 'Transfer Anonim';

    protected static ?int $navigationSort = 65;

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        // Transfer yang sudah SELESAI (terhubung via Verifikasi Data) atau BATAL (sudah
        // dibuatkan Refund) tidak tampil lagi.
        return parent::getEloquentQuery()->whereNotIn('status', ['SELESAI', 'BATAL']);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\DatePicker::make('tgl_transfer')->label('Tgl Transfer')->default(now()),
            Forms\Components\Select::make('coa_bank_code')->label('Bank Penerima')
                ->options(fn () => TransferAnonim::coaBankOptions())
                ->searchable()->placeholder('Pilih akun bank (Chart of Account)'),
            Forms\Components\TextInput::make('amount')->label('Nominal (Rp)')->numeric()->rupiah()->default(0),
            Forms\Components\TextInput::make('keterangan_bank')->label('Berita/Referensi Bank')->columnSpanFull(),
            Forms\Components\Textarea::make('keterangan')->label('Catatan')->rows(2)->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('tgl_transfer')->label('Tgl')->date('d M Y')->sortable(),
                Tables\Columns\TextColumn::make('coa_bank_code')->label('Bank')
                    ->getStateUsing(fn (TransferAnonim $r) => $r->coaBankName() ?: $r->bank?->name)->placeholder('—'),
                Tables\Columns\TextColumn::make('amount')->label('Nominal')->money('IDR')->sortable(),
                Tables\Columns\TextColumn::make('keterangan_bank')->label('Berita Bank')->limit(36)->placeholder('—'),
                Tables\Columns\TextColumn::make('customer.full_name')->label('Customer')->placeholder('—')->limit(20),
                Tables\Columns\TextColumn::make('status')->label('Status')->badge()->color(fn (?string $state) => match ($state) {
                    'SELESAI' => 'success', 'TERIDENTIFIKASI' => 'warning', default => 'gray',
                }),
            ])
            ->filters([Tables\Filters\SelectFilter::make('status')->options([
                'BARU' => 'Baru', 'TERIDENTIFIKASI' => 'Teridentifikasi',
            ])])
            ->headerActions([Tables\Actions\CreateAction::make()])
            ->actions([
                // Identifikasi & Jadikan TTS dihapus — status berubah otomatis (→ SELESAI)
                // saat transfer ini dipilih sebagai bukti di Verifikasi Data Booked.
                // Batalkan & Refund: transfer (mis. salah/ditarik kembali) → buat Refund DRAFT
                // (sumber TRANSFER_ANONIM) lalu diproses ke Account Payable BO.
                Tables\Actions\Action::make('batalRefund')->label('Batalkan & Refund')->icon('heroicon-o-arrow-uturn-left')->color('danger')
                    ->visible(fn (TransferAnonim $r) => $r->status !== 'BATAL' && (float) $r->amount > 0)
                    ->requiresConfirmation()
                    ->modalHeading('Batalkan Transfer Anonim & Buat Refund')
                    ->modalDescription(fn (TransferAnonim $r) => 'Transfer Rp '.number_format((float) $r->amount, 0, ',', '.').' akan dibatalkan & dibuatkan Refund (DRAFT) untuk diproses ke Account Payable BO.')
                    ->action(function (TransferAnonim $r) {
                        $refund = Refund::create([
                            'tenant_id' => $r->tenant_id ?? 1,
                            'tgl_refund' => null,
                            'jenis' => 'BOOKING',
                            'sumber' => 'TRANSFER_ANONIM',
                            'customer_id' => $r->customer_id,
                            'nilai_kotor' => (float) $r->amount,
                            'biaya_admin' => 0,
                            'nilai_refund' => (float) $r->amount,
                            'bank_id' => $r->bank_id,
                            'status' => 'DRAFT',
                            'keterangan' => 'Refund transfer anonim'.($r->keterangan_bank ? ' — '.$r->keterangan_bank : '').' (tgl '.optional($r->tgl_transfer)->format('d/m/Y').')',
                        ]);
                        $r->update(['status' => 'BATAL']);
                        Notification::make()->success()->title('Refund dibuat: '.$refund->no_refund)
                            ->body('Transfer anonim dibatalkan. Lanjut di Finance → Refund → Proses & Posting BO.')->persistent()->send();
                    }),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListTransferAnonims::route('/')];
    }
}
