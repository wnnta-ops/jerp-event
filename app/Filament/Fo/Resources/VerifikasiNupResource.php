<?php

namespace App\Filament\Fo\Resources;

use App\Filament\Fo\Resources\VerifikasiNupResource\Pages;
use App\Modules\FO\Launching\Models\NupRegistration;
use App\Modules\FO\Launching\Services\VerifikasiNupService;
use App\Modules\FO\MarketingSales\Models\Unit;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

class VerifikasiNupResource extends Resource
{
    protected static ?string $model = NupRegistration::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationGroup = 'NUP';

    protected static ?int $navigationSort = 30;

    protected static ?string $modelLabel = 'Verifikasi Data';

    protected static ?string $pluralModelLabel = 'Verifikasi Data Customer';

    protected static ?string $recordTitleAttribute = 'nama_pendaftar';

    public static function canCreate(): bool
    {
        return false; // registrations come from the public NUP form
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['verifiedBy', 'customer']))
            ->columns([
                Tables\Columns\TextColumn::make('no_nup')->label('No NUP')->badge()->searchable()->sortable()
                    ->placeholder('— belum approve'),
                Tables\Columns\TextColumn::make('urutan')->label('Urutan')->numeric()->alignEnd()->sortable()
                    ->placeholder('—'),
                // Ref Xendit = ID invoice ASLI dari Xendit (xendit_invoice_id) agar
                // bisa langsung dicocokkan di dashboard Xendit; ref internal kita
                // (xendit_external_id, mis. UTJ-NUP-1) tampil sbg baris kecil di bawah.
                // Klik → buka halaman invoice Xendit (xendit_invoice_url) di tab baru.
                Tables\Columns\TextColumn::make('xendit_invoice_id')->label('Ref Xendit')
                    ->placeholder('—')->toggleable()->copyable()
                    ->description(fn (NupRegistration $r) => $r->xendit_external_id ? 'Ref: '.$r->xendit_external_id : null)
                    ->url(fn (NupRegistration $r) => $r->xendit_invoice_url ?: null, shouldOpenInNewTab: true)
                    ->searchable(['xendit_invoice_id', 'xendit_external_id']),
                Tables\Columns\TextColumn::make('nama_pendaftar')->label('Nama')->searchable(),
                Tables\Columns\TextColumn::make('tgl_daftar')->label('Tanggal Input')->dateTime('d M Y')->sortable(),
                Tables\Columns\TextColumn::make('cara_bayar')->label('Cara Bayar')->badge()->placeholder('—'),
                Tables\Columns\TextColumn::make('tipe_nup')->label('Tipe NUP')->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('utj_amount')->label('Nilai UTJ')->money('IDR', divideBy: 0)->alignEnd(),
                Tables\Columns\TextColumn::make('pilihan_unit_1')->label('Pilihan 1')->placeholder('—')->wrap(),
                Tables\Columns\TextColumn::make('pilihan_unit_2')->label('Pilihan 2')->placeholder('—')->wrap()->toggleable(),
                Tables\Columns\TextColumn::make('pilihan_unit_3')->label('Pilihan 3')->placeholder('—')->wrap()->toggleable(),
                Tables\Columns\TextColumn::make('xendit_status')->label('Xendit')->badge()->placeholder('—')
                    ->color(fn (?string $state) => match ($state) {
                        'PAID' => 'success', 'PENDING' => 'warning', 'EXPIRED' => 'danger', default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('verif_status')->label('Status')->badge()
                    ->color(fn (string $state) => match ($state) {
                        'APPROVED' => 'success',
                        'PAID' => 'info',
                        'PENDING' => 'warning',
                        'MENUNGGU_BAYAR' => 'gray',
                        'REJECTED', 'REFUNDED' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\IconColumn::make('is_manual_approve')->label('Manual')->boolean()->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('verif_status')->label('Status verifikasi')->options([
                    'MENUNGGU_BAYAR' => 'Menunggu bayar',
                    'PAID' => 'Sudah bayar (UTJ)',
                    'APPROVED' => 'Approved',
                    'PENDING' => 'Pending',
                    'REJECTED' => 'Rejected',
                    'REFUNDED' => 'Refunded',
                ]),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('download_semua')->label('Download Semua (ZIP)')->icon('heroicon-o-archive-box-arrow-down')
                        ->url(fn (NupRegistration $r) => route('fo.customer.files-zip', $r->customer_id))
                        ->visible(fn (NupRegistration $r) => (bool) ($r->customer?->ktp_file_path || $r->customer?->npwp_file_path || $r->customer?->ktp_pasangan_file_path)),
                    Tables\Actions\Action::make('lihat_ktp')->label('Lihat KTP')->icon('heroicon-o-eye')
                        ->url(fn (NupRegistration $r) => route('fo.customer.file', [$r->customer_id, 'ktp']), shouldOpenInNewTab: true)
                        ->visible(fn (NupRegistration $r) => (bool) $r->customer?->ktp_file_path),
                    Tables\Actions\Action::make('unduh_ktp')->label('Unduh KTP')->icon('heroicon-o-arrow-down-tray')
                        ->url(fn (NupRegistration $r) => route('fo.customer.file', [$r->customer_id, 'ktp']).'?download=1')
                        ->visible(fn (NupRegistration $r) => (bool) $r->customer?->ktp_file_path),
                    Tables\Actions\Action::make('unduh_npwp')->label('Unduh NPWP')->icon('heroicon-o-arrow-down-tray')
                        ->url(fn (NupRegistration $r) => route('fo.customer.file', [$r->customer_id, 'npwp']).'?download=1')
                        ->visible(fn (NupRegistration $r) => (bool) $r->customer?->npwp_file_path),
                    Tables\Actions\Action::make('unduh_ktp_pasangan')->label('Unduh KTP Pasangan')->icon('heroicon-o-arrow-down-tray')
                        ->url(fn (NupRegistration $r) => route('fo.customer.file', [$r->customer_id, 'ktp_pasangan']).'?download=1')
                        ->visible(fn (NupRegistration $r) => (bool) $r->customer?->ktp_pasangan_file_path),
                ])->label('Berkas')->icon('heroicon-o-paper-clip')->button()->color('gray'),

                Tables\Actions\Action::make('paymentXendit')
                    ->label('Payment Xendit')->icon('heroicon-o-credit-card')->color('primary')
                    ->visible(fn (NupRegistration $r) => ! $r->isUtjPaid() && ! in_array($r->verif_status, ['REJECTED', 'REFUNDED', 'APPROVED'], true))
                    ->form([
                        Forms\Components\TextInput::make('amount')->label('Nominal UTJ')->numeric()->required()
                            ->default(fn (NupRegistration $r) => $r->utj_amount > 0 ? $r->utj_amount : 3_000_000),
                    ])
                    ->action(fn (NupRegistration $r, array $data) => app(VerifikasiNupService::class)->createXenditInvoice($r, (float) $data['amount'])),

                Tables\Actions\Action::make('expireXendit')
                    ->label('Expire Xendit')->icon('heroicon-o-clock')->color('gray')
                    ->visible(fn (NupRegistration $r) => $r->xendit_status === 'PENDING')
                    ->requiresConfirmation()
                    ->action(fn (NupRegistration $r) => app(VerifikasiNupService::class)->expireXendit($r)),

                Tables\Actions\Action::make('approve')
                    ->label('Approve')->icon('heroicon-o-check-circle')->color('success')
                    ->visible(fn (NupRegistration $r) => ! in_array($r->verif_status, ['APPROVED', 'REJECTED', 'REFUNDED'], true))
                    ->form([
                        Forms\Components\Placeholder::make('pilihan_unit_pendaftar')
                            ->label('Pilihan Unit Pendaftar')
                            ->content(fn (NupRegistration $r) => new HtmlString(
                                '1. '.e($r->pilihan_unit_1 ?: '—').'<br>'.
                                '2. '.e($r->pilihan_unit_2 ?: '—').'<br>'.
                                '3. '.e($r->pilihan_unit_3 ?: '—')
                            ))
                            ->helperText('Preferensi yang diisi pendaftar — ikut tercetak di SPRS pada kontrak.'),
                        Forms\Components\Toggle::make('manual')->label('Approve manual (UTJ dicek langsung di Xendit)')
                            ->live()
                            ->default(fn (NupRegistration $r) => ! $r->isUtjPaid()),
                        Forms\Components\Textarea::make('note')->label('Catatan / alasan manual')
                            ->required(fn (Forms\Get $get) => (bool) $get('manual'))
                            ->helperText('Wajib diisi bila approve manual.'),
                        Forms\Components\Textarea::make('rekomendasi_unit')
                            ->label('Rekomendasi Unit (dari Tim Verifikasi)')
                            ->rows(2)
                            ->maxLength(255)
                            ->default(fn (NupRegistration $r) => $r->rekomendasi_unit)
                            ->helperText('Rekomendasi unit dari tim verifikasi data — ikut tercetak di SPRS pada kontrak.'),
                        Forms\Components\Select::make('unit_id')
                            ->label('Unit untuk draft reservasi (opsional)')
                            ->options(fn () => Unit::query()
                                ->where('status', 'TERSEDIA')
                                ->get()
                                ->mapWithKeys(fn ($u) => [$u->id => trim(($u->cluster ? $u->cluster.' ' : '').($u->blok ? $u->blok.' ' : '').$u->no_unit)]))
                            ->searchable()
                            ->helperText('Pilihan unit pendaftar hanya preferensi teks. Pilih unit nyata untuk membuat draft reservasi; kosongkan jika unit dipilih di tahap berikutnya.'),
                    ])
                    ->action(fn (NupRegistration $r, array $data) => app(VerifikasiNupService::class)
                        ->approve($r, $data['note'] ?? null, (bool) ($data['manual'] ?? false), isset($data['unit_id']) ? (int) $data['unit_id'] : null, $data['rekomendasi_unit'] ?? null)),

                Tables\Actions\Action::make('pending')
                    ->label('Pending')->icon('heroicon-o-pause-circle')->color('warning')
                    ->visible(fn (NupRegistration $r) => ! in_array($r->verif_status, ['REFUNDED'], true))
                    ->form([Forms\Components\Textarea::make('note')->label('Catatan')])
                    ->action(fn (NupRegistration $r, array $data) => app(VerifikasiNupService::class)->pending($r, $data['note'] ?? null)),

                Tables\Actions\Action::make('reject')
                    ->label('Reject')->icon('heroicon-o-x-circle')->color('danger')
                    ->visible(fn (NupRegistration $r) => ! in_array($r->verif_status, ['REFUNDED'], true))
                    ->form([Forms\Components\Textarea::make('note')->label('Alasan reject')->required()])
                    ->action(fn (NupRegistration $r, array $data) => app(VerifikasiNupService::class)->reject($r, $data['note'])),

                Tables\Actions\Action::make('refund')
                    ->label('Refund')->icon('heroicon-o-arrow-uturn-left')->color('danger')
                    ->visible(fn (NupRegistration $r) => $r->isUtjPaid() && $r->verif_status !== 'REFUNDED')
                    ->form([Forms\Components\Textarea::make('note')->label('Alasan refund')->required()])
                    ->action(fn (NupRegistration $r, array $data) => app(VerifikasiNupService::class)->refund($r, $data['note'])),

                Tables\Actions\DeleteAction::make()->label('Delete'),
            ])
            ->defaultSort('tgl_daftar', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListVerifikasiNup::route('/'),
        ];
    }
}
