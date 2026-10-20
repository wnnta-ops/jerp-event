<?php

namespace App\Filament\Fo\Resources;

use App\Filament\Concerns\MematuhiIzin;
use App\Filament\Fo\Resources\RefundResource\Pages;
use App\Models\Bank;
use App\Modules\BO\IproBridge\Services\IproApPoster;
use App\Modules\FO\FinanceAR\Models\Refund;
use App\Modules\FO\MarketingSales\Models\Customer;
use App\Modules\FO\MarketingSales\Models\Kontrak;
use App\Modules\FO\MarketingSales\Models\KontrakTagihan;
use App\Support\Rupiah;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

class RefundResource extends Resource
{
    use MematuhiIzin;

    protected static ?string $model = Refund::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-uturn-left';

    protected static ?string $navigationGroup = 'Finance AR';

    protected static ?string $modelLabel = 'Refund';

    protected static ?string $pluralModelLabel = 'Refund / Cash Back';

    protected static ?int $navigationSort = 60;

    public static function form(Form $form): Form
    {
        // Refund yang LAHIR DARI PEMBATALAN: tertaut kontrak dan berjenis BOOKING. Angkanya
        // sudah diputuskan di Pembatalan Kontrak lalu disetujui berjenjang, jadi di sini
        // dikunci — diperiksa, bukan dihitung ulang atau diketik ulang.
        //
        // Cash Back (LEBIH_BAYAR) dan refund lepas tanpa kontrak TIDAK ikut terkunci: itu
        // memang diketik tangan, dan salah ketik harus bisa dibetulkan sesudah tersimpan.
        $dariPembatalan = fn (?Refund $record) => $record !== null
            && $record->kontrak_id
            && $record->jenis === 'BOOKING';

        // Refund = Total Dibayar − Admin. Admin bisa diisi sbg % dari Total ATAU Rp langsung.
        //
        // BERHENTI untuk refund yang lahir dari pembatalan. readOnly() menghalangi PENGETIKAN,
        // bukan peristiwa blur: kolom baca-saja tetap bisa difokus lalu ditinggalkan, dan
        // live(onBlur) tetap memanggil afterStateUpdated. Tanpa penjaga ini, sekali klik pada
        // kolom Total Dibayar sudah cukup menimpa angka yang disetujui — dan timpaannya
        // memakai round() sementara yang tersimpan hasil ceil(), sehingga layar menampilkan
        // Admin Rp 56.199.999 di atas dan Rp 56.200.000 di rincian bawahnya. Selisih satu
        // rupiah yang tidak terlihat sebagai kesalahan, hanya sebagai dua angka berbeda.
        $fromPersen = function (Forms\Get $get, Forms\Set $set, ?Refund $record) use ($dariPembatalan) {
            if ($dariPembatalan($record)) {
                return;
            }

            $kotor = Rupiah::toFloat($get('nilai_kotor'));
            $admin = round($kotor * (float) $get('biaya_admin_persen') / 100);
            $set('biaya_admin', $admin);
            $set('nilai_refund', max(0, $kotor - $admin));
        };

        // Pilih kontrak (KPR/CASH) → auto-isi dari rincian tagihan yang SUDAH DIBAYAR:
        //  - Total Dibayar = Σ pelunasan semua rincian (BF + DP + angsuran + ADM)
        //  - Biaya Admin    = Σ pelunasan rincian ber-tipe ADM (tidak di-refund)
        //  - Nilai Refund   = Total − ADM. (Pencairan KPR tak ikut: pelunasan=0 saat batal.)
        // Ikut BERHENTI untuk refund hasil pembatalan: mengganti kontraknya di sini akan
        // mengisi ulang seluruh angkanya dengan rumus lama "seluruh setoran − ADM", yang
        // sudah tidak berlaku dan bukan angka yang disetujui.
        $fillFromKontrak = function ($state, Forms\Set $set, ?Refund $record) use ($dariPembatalan) {
            if (! $state || $dariPembatalan($record)) {
                return;
            }
            $k = Kontrak::with('customer')->find($state);
            if (! $k) {
                return;
            }
            [$total, $admin] = self::paidTotals((int) $k->id);
            $set('nilai_kotor', $total);
            $set('biaya_admin', $admin);
            $set('biaya_admin_persen', $total > 0 ? round($admin / $total * 100, 2) : 0);
            $set('nilai_refund', max(0, $total - $admin));
            $set('customer_id', $k->customer_id);
            $set('bank_id', $k->customer?->bank_id);
            $set('rekening_tujuan', $k->customer?->no_rekening);
            // Sumber refund (untuk dikelompokkan di AP BO): turun dari tahap kontrak.
            $set('sumber', match ($k->tahap) {
                'SPR' => 'SPR',
                'SPRS' => 'SPRS',
                default => 'TRANSFER_ANONIM',
            });
        };

        return $form->schema([
            Forms\Components\TextInput::make('no_refund')->label('No. Refund')->disabled()->dehydrated(false)->placeholder('otomatis (RF-...)')->visibleOn('edit'),
            Forms\Components\Select::make('jenis')->label('Jenis')->default('BOOKING')->required()
                ->options(['BOOKING' => 'Refund Booking (batal)', 'LEBIH_BAYAR' => 'Lebih Bayar (Cash Back)']),
            Forms\Components\Hidden::make('sumber'),
            Forms\Components\DatePicker::make('tgl_refund')->label('Tgl Refund')->default(now()),
            // Pilih Kontrak → auto-hitung refund dari yang sudah dibayar (tanpa ADM). KPR & CASH.
            Forms\Components\Select::make('kontrak_id')->label('Kontrak (auto-hitung dari yang dibayar)')->searchable()->live()
                ->options(fn () => Kontrak::with(['customer', 'unit'])->latest('id')->limit(300)->get()
                    ->mapWithKeys(fn ($k) => [$k->id => trim($k->no_kontrak.' — '.($k->customer?->full_name ?? '').' — '.($k->unit?->kode_unit ?? ''), ' —')]))
                ->afterStateUpdated(fn ($state, Forms\Set $set, ?Refund $record) => $fillFromKontrak($state, $set, $record))
                ->helperText('Pilih kontrak → Total Dibayar, ADM, & Refund terisi otomatis (boleh diedit).'),
            Forms\Components\Select::make('customer_id')->label('Customer')->searchable()->live()
                ->options(fn () => Customer::query()->limit(300)->pluck('full_name', 'id'))
                ->afterStateUpdated(function ($state, Forms\Set $set) {
                    $cust = $state ? Customer::find($state) : null;
                    $set('bank_id', $cust?->bank_id);
                    $set('rekening_tujuan', $cust?->no_rekening);
                }),
            // KETIGA KOLOM UANG DI BAWAH MENAMPILKAN APA YANG TERSIMPAN, TITIK.
            //
            // Sebelumnya ketiganya memakai formatStateUsing() yang MENIMPA nilai tersimpan
            // dengan hitungan ulang dari paidTotals() setiap kali formulir dibuka. Untuk
            // refund yang lahir dari pembatalan, itu berarti angka yang sudah diputuskan
            // approval diganti diam-diam — dan tersimpan begitu tombol Simpan ditekan.
            //
            // Contoh nyata: RF-202609-00004 disetujui Rp 90.000.000 (DP+TDP 100 jt − admin
            // 10%), tetapi terbuka sebagai Rp 103.000.000 karena hitungan ulangnya memakai
            // "seluruh setoran − ADM" — rumus lama yang sudah tidak berlaku.
            //
            // Layar ini memang bukan tempat menghitung: hitungannya sudah selesai di
            // Pembatalan Kontrak dan disetujui berjenjang. Di sini angkanya DIPASTIKAN lalu
            // diposting ke BO.
            Forms\Components\TextInput::make('nilai_kotor')->label('Total Dibayar Customer (Rp)')->numeric()->rupiah()->default(0)
                ->readOnly(fn (Forms\Get $get, ?Refund $record) => $dariPembatalan($record) || filled($get('kontrak_id')))
                ->live(onBlur: true)->afterStateUpdated(fn (Forms\Get $get, Forms\Set $set, ?Refund $record) => $fromPersen($get, $set, $record))
                ->helperText(fn (?Refund $record) => $dariPembatalan($record)
                    ? 'Dasar perhitungan saat pembatalan disetujui.'
                    : 'Ikut rincian kontrak (BF+DP+angsuran+ADM). Tanpa kontrak: ketik manual.'),
            Forms\Components\TextInput::make('biaya_admin_persen')->label('Admin (% dari Total Dibayar)')->numeric()->suffix('%')->default(0)
                ->dehydrated(false)->live(onBlur: true)
                ->readOnly(fn (?Refund $record) => $dariPembatalan($record))
                // Diturunkan dari angka yang TERSIMPAN, bukan dihitung ulang dari tagihan.
                ->afterStateHydrated(function (Forms\Set $set, ?Refund $record) {
                    if ($record && (float) $record->nilai_kotor > 0) {
                        $set('biaya_admin_persen', round((float) $record->biaya_admin / (float) $record->nilai_kotor * 100, 2));
                    }
                })
                ->afterStateUpdated(fn (Forms\Get $get, Forms\Set $set, ?Refund $record) => $fromPersen($get, $set, $record))
                ->helperText('Atur potongan admin di sini → Admin (Rp) & Refund otomatis menyesuaikan.'),
            Forms\Components\TextInput::make('biaya_admin')->label('Admin / tidak di-refund (Rp)')->numeric()->rupiah()->default(0)->readOnly()
                ->helperText('= Total × % admin (otomatis). Default ikut ADM dari rincian.'),
            Forms\Components\TextInput::make('nilai_refund')->label('Nilai Refund (Rp)')->numeric()->rupiah()->default(0)
                ->readOnly(fn (?Refund $record) => $dariPembatalan($record))
                ->helperText(fn (?Refund $record) => $dariPembatalan($record)
                    ? 'Nilai yang DISETUJUI. Tidak dihitung ulang di sini — periksa, lalu Proses & Kirim ke BO.'
                    : '= Total Dibayar − Admin. Otomatis, tapi bisa diubah.'),
            // Rincian pembayaran (asal nominal) — seperti SPR, agar admin tahu dari mana.
            Forms\Components\Placeholder::make('rincian')->label('Rincian Pembayaran (asal nominal)')->columnSpanFull()
                ->content(fn (Forms\Get $get) => new HtmlString(self::rincianHtml(
                    $get('kontrak_id') ? (int) $get('kontrak_id') : null,
                    Rupiah::toFloat($get('biaya_admin')),
                    Rupiah::toFloat($get('nilai_refund')),
                ))),
            Forms\Components\Select::make('bank_id')->label('Bank Tujuan')->searchable()->live()
                ->options(fn () => Bank::orderBy('name')->pluck('name', 'id')->put(0, 'Lain-lain (ketik manual)'))
                ->dehydrateStateUsing(fn ($state) => ((int) $state) === 0 ? null : $state)
                ->afterStateHydrated(fn (Forms\Set $set, $state, $record) => $set('bank_id', $state ?: ($record?->bank_nama ? 0 : null)))
                ->helperText('Otomatis dari rekening customer. Pilih "Lain-lain" untuk ketik bank sendiri.'),
            Forms\Components\TextInput::make('bank_nama')->label('Nama Bank (ketik manual)')->maxLength(100)
                ->visible(fn (Forms\Get $get) => (int) $get('bank_id') === 0)
                ->required(fn (Forms\Get $get) => (int) $get('bank_id') === 0)
                ->dehydrateStateUsing(fn ($state, Forms\Get $get) => (int) $get('bank_id') === 0 ? $state : null),
            Forms\Components\TextInput::make('rekening_tujuan')->label('Rekening Tujuan'),
            Forms\Components\Select::make('status')->label('Status')->default('DRAFT')
                ->options(['DRAFT' => 'Draft', 'DIPROSES' => 'Diproses', 'SELESAI' => 'Selesai', 'BATAL' => 'Batal']),
            Forms\Components\Textarea::make('keterangan')->label('Keterangan')->rows(2)->columnSpanFull(),
        ])->columns(2);
    }

    /** [total dibayar, total ADM dibayar] dari rincian tagihan kontrak — 1 sumber angka. */
    public static function paidTotals(?int $kontrakId): array
    {
        if (! $kontrakId) {
            return [0.0, 0.0];
        }
        $t = KontrakTagihan::where('kontrak_id', $kontrakId)->get();

        return [(float) $t->sum('pelunasan'), (float) $t->where('tipe', 'ADM')->sum('pelunasan')];
    }

    /**
     * Tabel HTML rincian pembayaran (yang sudah dibayar) → asal Nilai Refund (gaya SPR).
     * $adminOverride / $refundOverride: jika diisi, footer Admin & Refund ikut nilai form
     * (mis. admin % yang diatur user) — bukan ADM tagihan saja → tetap clop dengan field atas.
     */
    public static function rincianHtml(?int $kontrakId, ?float $adminOverride = null, ?float $refundOverride = null): string
    {
        if (! $kontrakId) {
            return '<em style="color:#94a3b8;">Pilih Kontrak untuk melihat rincian pembayaran.</em>';
        }
        $rows = KontrakTagihan::where('kontrak_id', $kontrakId)->where('pelunasan', '>', 0)->orderBy('urutan')->get();
        if ($rows->isEmpty()) {
            return '<em style="color:#94a3b8;">Belum ada pembayaran pada kontrak ini.</em>';
        }
        $rp = fn ($v) => 'Rp '.number_format((float) $v, 0, ',', '.');
        $td = fn ($t, $align = 'left') => '<td style="padding:4px 10px;border-bottom:1px solid #e2e8f0;text-align:'.$align.';">'.$t.'</td>';

        $body = '';
        foreach ($rows as $r) {
            $isAdm = $r->tipe === 'ADM';
            $body .= '<tr style="'.($isAdm ? 'color:#b45309;' : '').'">'
                .$td('<strong>'.e((string) $r->tipe).'</strong>')
                .$td(e((string) $r->nama_tagihan).($isAdm ? ' <em>(tidak di-refund)</em>' : ''))
                .$td($rp($r->pelunasan), 'right').'</tr>';
        }
        $total = (float) $rows->sum('pelunasan');
        $admin = $adminOverride ?? (float) $rows->where('tipe', 'ADM')->sum('pelunasan');
        $refund = $refundOverride ?? max(0, $total - $admin);
        $footRow = fn (string $label, $val, string $style) => '<tr style="'.$style.'"><td colspan="2" style="padding:5px 10px;text-align:right;">'.$label.'</td>'
            .'<td style="padding:5px 10px;text-align:right;">'.$rp($val).'</td></tr>';

        return '<div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;font-size:12px;">'
            .'<thead><tr style="text-align:left;color:#64748b;border-bottom:1px solid #cbd5e1;">'
            .'<th style="padding:5px 10px;">Tipe</th><th style="padding:5px 10px;">Keterangan</th><th style="padding:5px 10px;text-align:right;">Dibayar</th></tr></thead>'
            .'<tbody>'.$body
            .$footRow('Total Dibayar', $total, 'font-weight:700;border-top:2px solid #94a3b8;')
            .$footRow('− Admin', $admin, 'color:#b45309;')
            .$footRow('NILAI REFUND', $refund, 'font-weight:800;color:#16a34a;')
            .'</tbody></table></div>';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('no_refund')->label('No')->badge()->color('primary')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('jenis')->label('Jenis')->badge()->color(fn (?string $state) => $state === 'BOOKING' ? 'info' : 'warning'),
                Tables\Columns\TextColumn::make('sumber')->label('Sumber')->badge()->color('gray')->placeholder('—')
                    ->formatStateUsing(fn (?string $state) => $state ? str_replace('_', ' ', $state) : '—'),
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
                // Proses refund: isi tgl & rekening, lalu KIRIM ke antrian Posting Invoice BO
                // (ditampung per jenis). BELUM jadi hutang AP — BO yang menerbitkan invoice
                // hutang dari Posting Invoice; baru setelah itu masuk Account Payable.
                Tables\Actions\Action::make('proses')->label('Proses & Kirim ke BO')->icon('heroicon-o-paper-airplane')->color('success')
                    ->visible(fn (Refund $r) => in_array($r->status, ['DRAFT', 'DIPROSES']) && (float) $r->nilai_refund > 0)
                    ->modalHeading('Proses Refund → Posting Invoice BO')
                    ->modalDescription('Isi tanggal & rekening, lalu kirim ke antrian Posting Invoice BO (ditampung per jenis). BO menerbitkan invoice hutang dari sana — baru kemudian masuk Account Payable.')
                    ->fillForm(fn (Refund $r) => ['tgl_refund' => $r->tgl_refund, 'bank_id' => $r->bank_id, 'rekening_tujuan' => $r->rekening_tujuan])
                    ->form([
                        Forms\Components\DatePicker::make('tgl_refund')->label('Tgl Refund')->default(now())->required(),
                        Forms\Components\Select::make('bank_id')->label('Bank Tujuan')->options(fn () => Bank::orderBy('name')->pluck('name', 'id'))->searchable(),
                        Forms\Components\TextInput::make('rekening_tujuan')->label('Rekening Tujuan'),
                    ])
                    ->action(function (Refund $r, array $data) {
                        $sumber = $r->sumber ?: match ($r->kontrak?->tahap) {
                            'SPR' => 'SPR', 'SPRS' => 'SPRS', default => 'TRANSFER_ANONIM',
                        };
                        $r->update([
                            'tgl_refund' => $data['tgl_refund'],
                            'bank_id' => $data['bank_id'] ?? null,
                            'rekening_tujuan' => $data['rekening_tujuan'] ?? null,
                            'sumber' => $sumber,
                            'status' => 'DIPROSES',
                        ]);
                        try {
                            app(IproApPoster::class)->queueRefund($r->load(['customer', 'kontrak']));
                            Notification::make()->success()->title('Refund dikirim ke Posting Invoice BO')
                                ->body('Refund '.$r->no_refund.' ('.str_replace('_', ' ', $sumber).') menunggu diposting di BO → Posting Invoice. Setelah diposting baru masuk Account Payable. Klik nomornya untuk membuka di BO.')
                                ->actions(\App\Modules\BO\IproBridge\Support\TautanBo::aksiPosting([(string) $r->no_refund]))->persistent()->send();
                        } catch (\Throwable $e) {
                            Notification::make()->warning()->title('Status DIPROSES, tapi kirim ke BO gagal')
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
