<?php

namespace App\Filament\Fo\Resources;

use App\Filament\Concerns\MematuhiIzin;
use App\Filament\Fo\Resources\VerifikasiNupResource\Pages;
use App\Modules\FO\Komisi\Models\Agent;
use App\Modules\FO\Launching\Models\NupRegistration;
use App\Modules\FO\Launching\Services\VerifikasiNupService;
use App\Modules\FO\MarketingSales\Models\Unit;
use Filament\Forms;
use Filament\Notifications\Notification;
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

                // Edit SPRS — seluruh isi dokumen, bukan lagi hanya email & telepon.
                //
                // Kolomnya dipilih dari apa yang BENAR-BENAR tercetak di sprs.blade.php, dan
                // ditulis ke tempat yang dibaca cetakan itu — karena dua bagiannya berbeda asal:
                //
                //   Nama · No KTP · Alamat   ← MASTER customer ($c->full_name, $c->nik,
                //                               $c->alamatRingkas()); pendaftaran hanya cadangan
                //   Telp · Email · Sales · Type · Cara Bayar · Pilihan Unit · Catatan
                //                            ← PENDAFTARAN ($reg->...)
                //
                // Karena itu bagian pertama menyimpan ke fo_customers. Dulu kolom-kolom itu
                // sengaja tidak dibuka dengan alasan satu customer bisa punya beberapa
                // pendaftaran; alasannya tetap benar, tapi menuliskannya ke pendaftaran saja
                // membuat koreksi TIDAK PERNAH tampak di SPRS — master yang menang. Nama, NIK,
                // dan alamat memang milik ORANGNYA, bukan milik satu berkas: kalau salah ketik,
                // salahnya di semua dokumen. Peringatannya ditaruh di layar, dan kenyataannya
                // hanya 2 dari 302 customer yang punya lebih dari satu pendaftaran.
                Tables\Actions\Action::make('edit_sprs')
                    ->label('Edit SPRS')->icon('heroicon-o-pencil-square')->color('warning')
                    ->modalHeading('Edit SPRS')
                    ->modalDescription('Semua isian di bawah ikut tercetak di SPRS. Cetak ulang setelah menyimpan.')
                    ->modalWidth('3xl')
                    ->modalSubmitActionLabel('Simpan')
                    ->fillForm(function (NupRegistration $r): array {
                        $c = $r->customer;

                        return [
                            'full_name' => $c?->full_name ?: $r->nama_pendaftar,
                            'nik' => $c?->nik ?: $r->nik,
                            'jalan' => $c?->jalan ?: $c?->address,
                            'rt_rw' => $c?->rt_rw,
                            'kelurahan' => $c?->kelurahan,
                            'kecamatan' => $c?->kecamatan,
                            'city' => $c?->city,
                            'province' => $c?->province,

                            'email' => $r->email ?: $c?->email,
                            'phone' => $r->phone ?: $c?->mobile,
                            'agent_id' => $r->agent_id,
                            'tipe_nup' => $r->tipe_nup,
                            'cara_bayar' => $r->cara_bayar,
                            'pilihan_unit_1' => $r->pilihan_unit_1,
                            'pilihan_unit_2' => $r->pilihan_unit_2,
                            'pilihan_unit_3' => $r->pilihan_unit_3,
                            'keterangan' => $r->keterangan,
                        ];
                    })
                    ->form([
                        Forms\Components\Section::make('Data Customer')
                            ->description('Tersimpan ke master customer — ikut berlaku pada dokumen lain milik orang yang sama.')
                            ->columns(2)
                            ->schema([
                                Forms\Components\TextInput::make('full_name')->label('Nama Lengkap')
                                    ->required()->maxLength(255),
                                Forms\Components\TextInput::make('nik')->label('No. KTP / NIK')
                                    ->maxLength(32),
                                Forms\Components\TextInput::make('jalan')->label('Alamat')
                                    ->columnSpanFull()->maxLength(255),
                                Forms\Components\TextInput::make('rt_rw')->label('RT/RW')->maxLength(32),
                                Forms\Components\TextInput::make('kelurahan')->label('Kelurahan')->maxLength(100),
                                Forms\Components\TextInput::make('kecamatan')->label('Kecamatan')->maxLength(100),
                                Forms\Components\TextInput::make('city')->label('Kota / Kabupaten')->maxLength(100),
                                Forms\Components\TextInput::make('province')->label('Provinsi')->maxLength(100),
                            ]),

                        Forms\Components\Section::make('Data Pendaftaran')
                            ->description('Hanya berlaku untuk pendaftaran NUP ini.')
                            ->columns(2)
                            ->schema([
                                Forms\Components\TextInput::make('email')->label('Email')
                                    ->email()->required()->maxLength(255),
                                Forms\Components\TextInput::make('phone')->label('No. Telepon / HP')
                                    ->tel()->required()->maxLength(32),

                                // Satu pilihan, dua kolom: agent_id dipakai komisi/BLT dan
                                // ref_sales_name adalah potret label untuk SPRS/SPR. Keduanya
                                // ditulis bersama supaya cetakan dan komisi tidak menyebut
                                // sales yang berbeda.
                                Forms\Components\Select::make('agent_id')->label('Sales')
                                    ->options(fn () => Agent::query()->where('is_active', true)->salesPicker()
                                        ->orderBy('name')->get()
                                        ->mapWithKeys(fn (Agent $a) => [$a->id => ($a->code ? $a->code.' — ' : '').$a->name]))
                                    ->searchable()->preload()->placeholder('— tanpa sales —')
                                    ->helperText('Ikut menentukan komisi, bukan hanya nama di SPRS.'),

                                Forms\Components\Select::make('cara_bayar')->label('Cara Bayar')
                                    ->options(['KPR' => 'KPR', 'CASH' => 'CASH', 'IN HOUSE' => 'IN HOUSE'])
                                    ->native(false),

                                Forms\Components\TextInput::make('tipe_nup')->label('Type')->maxLength(64),
                                Forms\Components\TextInput::make('pilihan_unit_1')->label('Pilihan Unit 1')->maxLength(100),
                                Forms\Components\TextInput::make('pilihan_unit_2')->label('Pilihan Unit 2')->maxLength(100),
                                Forms\Components\TextInput::make('pilihan_unit_3')->label('Pilihan Unit 3')->maxLength(100),

                                Forms\Components\Textarea::make('keterangan')->label('Catatan')
                                    ->rows(2)->columnSpanFull()->maxLength(500),
                            ]),
                    ])
                    ->action(function (NupRegistration $r, array $data): void {
                        $agent = ! empty($data['agent_id']) ? Agent::find($data['agent_id']) : null;

                        $r->update([
                            'email' => $data['email'],
                            'phone' => $data['phone'],
                            'agent_id' => $data['agent_id'] ?: null,
                            // Potret label ikut diperbarui: SPRS mendahulukan ref_sales_name,
                            // jadi membiarkannya berarti cetakan menyebut sales yang lama
                            // sementara komisinya sudah pindah.
                            'ref_sales_name' => $agent?->sales_label,
                            'tipe_nup' => $data['tipe_nup'] ?: null,
                            'cara_bayar' => $data['cara_bayar'] ?: null,
                            'pilihan_unit_1' => $data['pilihan_unit_1'] ?: null,
                            'pilihan_unit_2' => $data['pilihan_unit_2'] ?: null,
                            'pilihan_unit_3' => $data['pilihan_unit_3'] ?: null,
                            'keterangan' => $data['keterangan'] ?: null,
                            // Pendaftaran ikut disamakan supaya tidak tertinggal bila kelak
                            // dibaca tanpa customer (mis. pendaftaran tanpa master).
                            'nama_pendaftar' => $data['full_name'],
                            'nik' => $data['nik'] ?: null,
                        ]);

                        if ($c = $r->customer) {
                            $c->update([
                                'full_name' => $data['full_name'],
                                'nik' => $data['nik'] ?: null,
                                'jalan' => $data['jalan'] ?: null,
                                'rt_rw' => $data['rt_rw'] ?: null,
                                'kelurahan' => $data['kelurahan'] ?: null,
                                'kecamatan' => $data['kecamatan'] ?: null,
                                'city' => $data['city'] ?: null,
                                'province' => $data['province'] ?: null,
                            ]);
                        }

                        Notification::make()
                            ->title('SPRS diperbarui')
                            ->body('Cetak ulang SPRS untuk melihat hasilnya.')
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
