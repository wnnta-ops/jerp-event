<?php

namespace App\Filament\Fo\Resources;

use App\Filament\Concerns\MematuhiIzin;
use App\Filament\Fo\Resources\VerifikasiNupResource\Pages;
use App\Modules\FO\Launching\Models\NupRegistration;
use App\Modules\FO\Launching\Services\VerifikasiNupService;
use App\Modules\FO\MarketingSales\Models\Unit;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\HtmlString;

class VerifikasiNupResource extends Resource
{
    use MematuhiIzin;

    protected static ?string $model = NupRegistration::class;

    // Hanya pendaftaran NUP (launching); Booked Manual punya menu verifikasi sendiri.
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('jenis', 'NUP');
    }

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
                // Bank Pilihan (KPR) — snapshot nama saat mendaftar.
                Tables\Columns\TextColumn::make('bank_nama')->label('Bank')->badge()->color('gray')->placeholder('—')->searchable(),
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

                // Cetak SPRS — SENGAJA tersedia sejak sebelum approve, supaya berkasnya
                // bisa dicetak dan ditandatangani customer lebih dulu. Sebelum approve
                // nomor NUP memang belum terbit, jadi kolom nomor di dokumen masih kosong;
                // itu diterima karena penomoran baru sah setelah verifikasi disetujui.
                // Signed URL: rute SPRS memang publik (customer membukanya tanpa login),
                // jadi tautannya harus bertanda tangan seperti yang dikirim ke customer.
                Tables\Actions\Action::make('cetak_sprs')
                    ->label('Cetak SPRS')->icon('heroicon-o-printer')->color('info')
                    ->url(fn (NupRegistration $r) => URL::signedRoute('fo.cetak.sprs', ['registration' => $r->id]))
                    ->openUrlInNewTab(),

                // SPR Bank — cetakan untuk berkas pengajuan ke bank. Halaman cetak
                // saja: bisa disunting di layar lalu dicetak, tidak menyimpan apa pun.
                Tables\Actions\Action::make('cetak_spr_bank')
                    ->label('Cetak SPR Bank')->icon('heroicon-o-building-library')->color('info')
                    ->url(fn (NupRegistration $r) => URL::signedRoute('fo.cetak.spr-bank', ['registration' => $r->id]))
                    ->openUrlInNewTab()
                    // Hanya setelah APPROVED. Sebelum itu No NUP belum terbit, jadi
                    // nomor suratnya jatuh ke id pendaftaran dan halaman QR tak dicetak —
                    // berkas yang belum layak diserahkan ke bank.
                    ->visible(fn (NupRegistration $r) => $r->verif_status === 'APPROVED'),

                // Edit SPRS — HANYA email & no. telepon. Dua kolom ini yang sering keliru
                // diketik customer di form publik dan membuat kiriman SPRS tidak sampai.
                // Kolom lain (nama, NIK, alamat, pilihan unit, nominal) TIDAK dibuka di
                // sini: itu data pendaftaran yang jadi dasar verifikasi, bukan sekadar
                // tampilan dokumen. Perubahan ditulis ke data PENDAFTARAN saja, tidak ke
                // master customer — satu customer bisa punya beberapa pendaftaran/kontrak
                // dan tidak boleh ikut berubah gara-gara koreksi satu berkas.
                Tables\Actions\Action::make('edit_sprs')
                    ->label('Edit SPRS')->icon('heroicon-o-pencil-square')->color('warning')
                    ->modalHeading('Edit SPRS — Email & Telepon')
                    ->modalDescription('Hanya email dan nomor telepon yang bisa diubah. Data lain mengikuti pendaftaran.')
                    ->modalSubmitActionLabel('Simpan')
                    ->fillForm(fn (NupRegistration $r) => [
                        'email' => $r->email ?: $r->customer?->email,
                        'phone' => $r->phone ?: $r->customer?->mobile,
                    ])
                    ->form([
                        Forms\Components\TextInput::make('email')->label('Email')
                            ->email()->required()->maxLength(255),
                        Forms\Components\TextInput::make('phone')->label('No. Telepon / HP')
                            ->tel()->required()->maxLength(32),
                    ])
                    ->action(function (NupRegistration $r, array $data): void {
                        $r->update([
                            'email' => $data['email'],
                            'phone' => $data['phone'],
                        ]);

                        \Filament\Notifications\Notification::make()
                            ->title('SPRS diperbarui')
                            ->body('Email & telepon pendaftaran diubah. Cetak ulang SPRS untuk melihat hasilnya.')
                            ->success()->send();
                    }),

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
