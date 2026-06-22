<?php

namespace App\Filament\Fo\Resources;

use App\Filament\Fo\Resources\RefundResource\Pages;
use App\Models\Bank;
use App\Modules\FO\FinanceAR\Models\Refund;
use App\Modules\FO\MarketingSales\Models\Customer;
use App\Support\Rupiah;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class RefundResource extends Resource
{
    protected static ?string $model = Refund::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-uturn-left';

    protected static ?string $navigationGroup = 'Finance AR';

    protected static ?string $modelLabel = 'Refund';

    protected static ?string $pluralModelLabel = 'Refund / Cash Back';

    protected static ?int $navigationSort = 60;

    public static function form(Form $form): Form
    {
        // Biaya admin = % dari UTJ (Nilai Kotor); Nilai Refund = Nilai Kotor − Biaya Admin.
        $recompute = function (Forms\Get $get, Forms\Set $set) {
            $kotor = Rupiah::toFloat($get('nilai_kotor'));
            $admin = round($kotor * (float) $get('biaya_admin_persen') / 100);
            $set('biaya_admin', $admin);
            $set('nilai_refund', max(0, $kotor - $admin));
        };

        return $form->schema([
            Forms\Components\TextInput::make('no_refund')->label('No. Refund')->disabled()->dehydrated(false)->placeholder('otomatis (RF-...)')->visibleOn('edit'),
            Forms\Components\Select::make('jenis')->label('Jenis')->default('BOOKING')->required()
                ->options(['BOOKING' => 'Refund Booking (batal)', 'LEBIH_BAYAR' => 'Lebih Bayar (Cash Back)']),
            Forms\Components\DatePicker::make('tgl_refund')->label('Tgl Refund')->default(now()),
            // Pilih customer → Bank & Rekening Tujuan otomatis mengikuti data customer.
            Forms\Components\Select::make('customer_id')->label('Customer')->searchable()->live()
                ->options(fn () => Customer::query()->limit(300)->pluck('full_name', 'id'))
                ->afterStateUpdated(function ($state, Forms\Set $set) {
                    $cust = $state ? Customer::find($state) : null;
                    $set('bank_id', $cust?->bank_id);
                    $set('rekening_tujuan', $cust?->no_rekening);
                }),
            Forms\Components\TextInput::make('nilai_kotor')->label('Nilai Kotor / UTJ (Rp)')->numeric()->rupiah()->default(0)
                ->live(onBlur: true)->afterStateUpdated(fn (Forms\Get $get, Forms\Set $set) => $recompute($get, $set)),
            Forms\Components\TextInput::make('biaya_admin_persen')->label('Biaya Admin (% dari UTJ)')->numeric()->suffix('%')->default(0)
                ->dehydrated(false)->live(onBlur: true)
                ->afterStateHydrated(function (Forms\Set $set, $record) {
                    if ($record && (float) $record->nilai_kotor > 0) {
                        $set('biaya_admin_persen', round((float) $record->biaya_admin / (float) $record->nilai_kotor * 100, 2));
                    }
                })
                ->afterStateUpdated(fn (Forms\Get $get, Forms\Set $set) => $recompute($get, $set)),
            Forms\Components\TextInput::make('biaya_admin')->label('Biaya Admin (Rp)')->numeric()->rupiah()->default(0)->readOnly()
                ->helperText('= Nilai Kotor × Biaya Admin %'),
            Forms\Components\TextInput::make('nilai_refund')->label('Nilai Refund (Rp)')->numeric()->rupiah()->default(0)->readOnly()
                ->helperText('= Nilai Kotor − Biaya Admin'),
            Forms\Components\Select::make('bank_id')->label('Bank Tujuan')->options(fn () => Bank::orderBy('name')->pluck('name', 'id'))->searchable()
                ->helperText('Otomatis dari data rekening customer; bisa diubah.'),
            Forms\Components\TextInput::make('rekening_tujuan')->label('Rekening Tujuan'),
            Forms\Components\Select::make('status')->label('Status')->default('DRAFT')
                ->options(['DRAFT' => 'Draft', 'DIPROSES' => 'Diproses', 'SELESAI' => 'Selesai', 'BATAL' => 'Batal']),
            Forms\Components\Textarea::make('keterangan')->label('Keterangan')->rows(2)->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('no_refund')->label('No')->badge()->color('primary')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('jenis')->label('Jenis')->badge()->color(fn (?string $state) => $state === 'BOOKING' ? 'info' : 'warning'),
                Tables\Columns\TextColumn::make('customer.full_name')->label('Customer')->placeholder('—')->limit(24),
                Tables\Columns\TextColumn::make('kontrak.unit.kode_unit')->label('Unit')->placeholder('—')->searchable(),
                Tables\Columns\TextColumn::make('tgl_refund')->label('Tgl')->date('d M Y')->sortable(),
                Tables\Columns\TextColumn::make('nilai_refund')->label('Refund')->money('IDR'),
                Tables\Columns\TextColumn::make('status')->label('Status')->badge()->color(fn (?string $state) => match ($state) {
                    'SELESAI' => 'success', 'BATAL' => 'danger', 'DIPROSES' => 'warning', default => 'gray',
                }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('jenis')->options(['BOOKING' => 'Booking', 'LEBIH_BAYAR' => 'Lebih Bayar']),
                Tables\Filters\SelectFilter::make('status')->options(['DRAFT' => 'Draft', 'DIPROSES' => 'Diproses', 'SELESAI' => 'Selesai', 'BATAL' => 'Batal']),
            ])
            ->headerActions([Tables\Actions\CreateAction::make()])
            ->actions([
                // Proses refund: isi tgl & rekening, tandai SELESAI, lalu posting jurnal
                // kas keluar ke BO (FOBO bridge, jenis REFUND).
                Tables\Actions\Action::make('proses')->label('Proses & Posting BO')->icon('heroicon-o-check-circle')->color('success')
                    ->visible(fn (Refund $r) => in_array($r->status, ['DRAFT', 'DIPROSES']) && (float) $r->nilai_refund > 0)
                    ->modalHeading('Proses Refund → Posting BO')
                    ->modalDescription('Isi tanggal & rekening pembayaran refund, lalu tandai SELESAI dan posting jurnal kas keluar ke BO.')
                    ->fillForm(fn (Refund $r) => ['tgl_refund' => $r->tgl_refund, 'bank_id' => $r->bank_id, 'rekening_tujuan' => $r->rekening_tujuan])
                    ->form([
                        Forms\Components\DatePicker::make('tgl_refund')->label('Tgl Refund')->default(now())->required(),
                        Forms\Components\Select::make('bank_id')->label('Bank Tujuan')->options(fn () => Bank::orderBy('name')->pluck('name', 'id'))->searchable(),
                        Forms\Components\TextInput::make('rekening_tujuan')->label('Rekening Tujuan'),
                    ])
                    ->action(function (Refund $r, array $data) {
                        $r->update([
                            'tgl_refund' => $data['tgl_refund'],
                            'bank_id' => $data['bank_id'] ?? null,
                            'rekening_tujuan' => $data['rekening_tujuan'] ?? null,
                            'status' => 'SELESAI',
                        ]);
                        try {
                            \App\Modules\FO\Shared\Events\FoFinancialEvent::dispatch(
                                jenis: 'REFUND',
                                sourceModul: 'FO.FinanceAR',
                                sourceNo: $r->no_refund,
                                amount: (float) $r->nilai_refund,
                                tgl: $r->tgl_refund->toDateString(),
                                keterangan: 'Refund '.$r->no_refund.($r->customer ? ' — '.$r->customer->full_name : ''),
                                meta: ['refund_id' => $r->id, 'customer_id' => $r->customer_id, 'kontrak_id' => $r->kontrak_id],
                            );
                            \Filament\Notifications\Notification::make()->success()->title('Refund SELESAI & diposting ke BO')
                                ->body('Jurnal kas keluar refund '.$r->no_refund.' dikirim ke BO.')->send();
                        } catch (\Throwable $e) {
                            \Filament\Notifications\Notification::make()->warning()->title('Refund SELESAI, tapi posting BO gagal')
                                ->body($e->getMessage())->persistent()->send();
                        }
                    }),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListRefunds::route('/')];
    }
}
