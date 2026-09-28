<?php

namespace App\Filament\Fo\Pages;

use App\Filament\Concerns\MematuhiIzinHalaman;
use App\Filament\Fo\Resources\RefundResource;
use App\Modules\BO\IproBridge\Services\RekomBatalCashService;
use App\Modules\FO\Approval\Models\Approval;
use App\Modules\FO\Approval\Services\ApprovalService;
use App\Modules\FO\MarketingSales\Models\Kontrak;
use App\Modules\FO\MarketingSales\Services\HitungPembatalan;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;

/**
 * Pembatalan Kontrak — daftar kontrak SPR; ajukan pembatalan (alasan + pelunasan/
 * pengembalian). Pengajuan masuk ke Approval Pembatalan (BATAL_KONTRAK); kontrak baru
 * BATAL setelah disetujui (unit dikembalikan TERSEDIA).
 */
class PembatalanKontrak extends Page implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;
    use MematuhiIzinHalaman;

    protected static ?string $navigationIcon = 'heroicon-o-x-circle';

    protected static ?string $title = 'Pembatalan Kontrak';

    protected static ?string $navigationGroup = 'Marketing & Sales';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'pembatalan-kontrak';

    protected static string $view = 'filament.fo.pages.pembatalan-kontrak';

    /**
     * Nomor SPR yang sudah punya Memo Rekom Batal → sumber penandanya.
     *
     * Nilainya kolom `source`, bukan sekadar true: satu SPR hanya boleh punya SATU penanda
     * (unik tenant_id+spr_number), sedangkan cara bayar sebuah kontrak masih bisa berubah
     * sesudah penandanya terbit. Tanpa menyimpan sumbernya, memo cash lama akan membuka kunci
     * kontrak yang kini berjalan sebagai KPR — lihat rekomBatalSiap().
     *
     * Diambil SEKALI per request, bukan sekali per baris: closure gerbang tombol berjalan
     * untuk tiap baris tabel, dan halaman ini sudah menambah satu query per baris untuk kolom
     * "Pembatalan". Satu query lagi per baris akan melipatgandakan beban itu tanpa alasan —
     * daftar rekomendasinya sendiri pendek.
     *
     * Dibungkus try/catch dan memulangkan null bila ipro_bo tak terjangkau. Itu disengaja:
     * gangguan sambungan tidak boleh mengunci SELURUH tombol pembatalan di layar ini
     * (lihat rekomBatalSiap, yang memilih membuka tombol daripada mematikannya).
     *
     * @var array<string,string>|null
     */
    private static ?array $rekomBatal = null;

    private static function daftarRekomBatal(): ?array
    {
        if (self::$rekomBatal !== null) {
            return self::$rekomBatal;
        }

        try {
            $conn = (string) config('ipro.connection', 'ipro_bo');

            return self::$rekomBatal = DB::connection($conn)->table('kpr_cancel_recommendations')
                // tenant_id WAJIB disaring: nomor SPR hanya unik di dalam satu tenant, dan
                // tanpa penyaring ini rekomendasi milik tenant lain yang kebetulan bernomor
                // sama akan ikut membuka tombol di sini.
                ->where('tenant_id', (int) config('ipro.tenant_id', 1))
                ->pluck('source', 'spr_number')
                ->map(fn ($s) => (string) $s)->all();
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Boleh diajukan pembatalannya?
     *
     * DUA keluarga cara bayar yang digerbangi, masing-masing oleh layarnya sendiri:
     *  - KPR                  → Memo Rekom Batal di Reject BI Checking / Reject Proses KPR
     *                           (modul KPR, Back Office).
     *  - CASH & CASH BERTAHAP → Memo Rekom Batal di Collection → Rekom Batal Cash (layar ini
     *                           satu aplikasi, lihat RekomBatalCash).
     *
     * CICILAN (dan cara bayar lain yang mungkin menyusul) SENGAJA DIBIARKAN TERBUKA: belum ada
     * layar yang menerbitkan memonya, dan mengunci tanpa penerbit berarti kontraknya tidak akan
     * pernah bisa dibatalkan sama sekali — bukan pengetatan, melainkan jalan buntu.
     *
     * Sumber penandanya ikut diperiksa, bukan hanya keberadaannya. Alasannya di docblock
     * RekomBatalCashService::sumberPembuka(): cara bayar bisa berganti setelah memo terbit.
     */
    private static function rekomBatalSiap(Kontrak $k): bool
    {
        $sumberSah = RekomBatalCashService::sumberPembuka($k->cara_bayar);

        if ($sumberSah === null) {
            return true;
        }

        $daftar = self::daftarRekomBatal();

        // Tak terjangkau → jangan mengunci. Menahan pembatalan karena sambungan ke modul lain
        // sedang putus adalah kegagalan yang tidak bisa dibaca siapa pun dari layar ini.
        if ($daftar === null) {
            return true;
        }

        $sumber = $daftar[(string) $k->no_kontrak] ?? null;

        return $sumber !== null && in_array($sumber, $sumberSah, true);
    }

    /** Layar yang menerbitkan memonya — dipakai tooltip supaya operator tahu harus ke mana. */
    private static function asalMemo(Kontrak $k): string
    {
        return RekomBatalCashService::adalahCash($k->cara_bayar)
            ? 'Collection → Rekom Batal Cash'
            : 'modul KPR (Reject BI Checking / Reject Proses KPR)';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(Kontrak::query()->with(['unit', 'customer', 'agent'])->whereIn('tahap', ['SPR', 'SPRS'])->where('status', 'AKTIF'))
            ->heading('Daftar Kontrak (SPR & SPRS)')
            ->description('Kontrak SPR (final) & SPRS (booking). Ajukan pembatalan — pengajuan masuk ke Approval Pembatalan; saat disetujui, Refund otomatis dibuat (sumber SPR/SPRS).')
            ->columns([
                Tables\Columns\TextColumn::make('no_kontrak')->label('No. Kontrak')->badge()->color('info')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('tahap')->label('Tahap')->badge()->color(fn (?string $state) => $state === 'SPR' ? 'success' : 'warning'),
                Tables\Columns\TextColumn::make('unit.kode_unit')->label('Unit')->placeholder('—')->searchable(),
                Tables\Columns\TextColumn::make('customer.full_name')->label('Customer')->searchable()->weight('bold'),
                Tables\Columns\TextColumn::make('agent.name')->label('Sales')->placeholder('—'),
                Tables\Columns\TextColumn::make('total_kontrak')->label('Nilai Kontrak')->money('IDR', divideBy: 0)->alignEnd(),
                Tables\Columns\TextColumn::make('batal_count')->label('Pembatalan')->badge()
                    ->getStateUsing(fn (Kontrak $record) => Approval::where('subject_type', Kontrak::class)->where('subject_id', $record->id)->where('jenis', 'BATAL_KONTRAK')->count())
                    ->formatStateUsing(fn ($state) => $state.'×')
                    ->color(fn ($state) => $state > 0 ? 'danger' : 'gray'),
            ])
            ->actions([
                Tables\Actions\Action::make('riwayat')->label('Riwayat')->icon('heroicon-o-clock')->color('gray')->link()
                    ->modalHeading(fn (Kontrak $record) => 'Riwayat Pembatalan — '.$record->no_kontrak)
                    ->modalSubmitAction(false)->modalCancelActionLabel('Tutup')
                    ->modalContent(fn (Kontrak $record) => new HtmlString(AdjustmentKontrak::riwayatApprovalHtml($record, 'BATAL_KONTRAK'))),
                Tables\Actions\Action::make('ajukan')->label('Ajukan Pembatalan')->icon('heroicon-o-x-circle')->color('danger')->button()
                    // Gerbang: kontrak KPR & cash baru boleh diajukan sesudah layar yang sesuai
                    // menerbitkan Memo Rekom Batal untuk SPR-nya. Filament menolaknya juga di
                    // SISI SERVER (HasActions::mountTableAction memeriksa isDisabled), jadi
                    // tombol ini tidak bisa ditembus dengan memanggil aksinya langsung.
                    ->disabled(fn (Kontrak $record) => ! self::rekomBatalSiap($record))
                    ->tooltip(fn (Kontrak $record) => self::rekomBatalSiap($record)
                        ? null
                        : 'Terkunci: belum ada Memo Rekom Batal dari '.self::asalMemo($record).' untuk '.$record->no_kontrak.'.')
                    ->modalHeading('Pengajuan Pembatalan Kontrak')
                    ->modalDescription(fn (Kontrak $record) => 'Status kontrak '.$record->no_kontrak.' akan menjadi BATAL setelah disetujui.')
                    ->modalWidth('xl')
                    ->modalSubmitActionLabel('Ajukan ke Approval')
                    ->form([
                        Forms\Components\Radio::make('alasan')->label('Alasan Pembatalan')->required()->live()->columns(2)
                            ->afterStateUpdated(fn (Forms\Get $get, Forms\Set $set, Kontrak $record) => self::isiRefund($record, $get, $set))
                            ->options([
                                'BATAL_SEPIHAK' => 'Batal Sepihak',
                                'REJECT_BANK' => 'Reject Bank',
                                'KOL_2' => 'Kol 2',
                                'KOL_3' => 'Kol 3',
                                'KOL_4' => 'Kol 4',
                                'KOL_5' => 'Kol 5',
                                'TUTUP_BUKU' => 'Tutup Buku',
                                'LAINNYA' => 'Lainnya',
                            ]),

                        // Persennya DIKETIK, tidak diturunkan dari keadaan kontrak. Praktiknya 10%
                        // bila pembeli mundur sesudah membayar DP dan 30% bila batal menjelang akad,
                        // tetapi batasnya soal kesepakatan — bukan sesuatu yang bisa dibaca sistem.
                        Forms\Components\TextInput::make('admin_persen')->label('Administrasi dipotong dari DP')
                            ->numeric()->suffix('%')->default(0)->minValue(0)->maxValue(100)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Forms\Get $get, Forms\Set $set, Kontrak $record) => self::isiRefund($record, $get, $set))
                            ->visible(fn (Forms\Get $get) => $get('alasan') === HitungPembatalan::ALASAN_SEPIHAK)
                            ->required(fn (Forms\Get $get) => $get('alasan') === HitungPembatalan::ALASAN_SEPIHAK)
                            ->helperText('Isi 0 bila DP dikembalikan utuh. UTJ tidak ikut dikembalikan pada Batal Sepihak.'),

                        Forms\Components\Textarea::make('alasan_lainnya')->label('Alasan Lainnya')->rows(2)
                            ->visible(fn (Forms\Get $get) => $get('alasan') === 'LAINNYA')
                            ->required(fn (Forms\Get $get) => $get('alasan') === 'LAINNYA'),
                        Forms\Components\DatePicker::make('tgl_pembatalan')->label('Tgl Pembatalan')->default(now())->required(),

                        Forms\Components\TextInput::make('refund_utj')->label('Pengembalian ke customer')->numeric()->rupiah()->default(0)
                            ->readOnly(fn (Forms\Get $get) => $get('alasan') !== 'LAINNYA')
                            ->helperText('Kol 2-5 / Reject Bank / Tutup Buku: UTJ dikembalikan utuh. Batal Sepihak: DP dikurangi administrasi — UTJ hangus. Lainnya: isi manual. Pencairan di menu Finance → Refund.'),

                        // Diperlihatkan sebagai rincian bertingkat, bukan satu angka jadi: operator
                        // harus bisa melihat DARI MANA angkanya, sebab dialah yang menjelaskannya
                        // kepada pembeli.
                        Forms\Components\Placeholder::make('hitungan')->label('Hitungan')
                            ->content(fn (Forms\Get $get, Kontrak $record) => new HtmlString(self::hitunganHtml($record, $get))),

                        Forms\Components\Placeholder::make('rincian')->label('Rincian yang sudah dibayar customer')
                            ->content(fn (Kontrak $record) => new HtmlString(RefundResource::rincianHtml((int) $record->id))),
                    ])
                    ->action(function (Kontrak $record, array $data) {
                        $alasanLabel = $data['alasan'] === 'LAINNYA'
                            ? ($data['alasan_lainnya'] ?? 'Lainnya')
                            : str_replace('_', ' ', $data['alasan']);

                        // Dihitung ULANG di sini, tidak dipercayakan pada nilai yang datang dari
                        // formulir: kolom Refund read-only hanya menghalangi pengetikan, bukan
                        // pengiriman. Satu-satunya yang benar-benar diketik operator adalah persen
                        // administrasi dan — pada alasan "Lainnya" — nominalnya sendiri.
                        $h = app(HitungPembatalan::class)->pengembalian($record, $data['alasan'] ?? null, (float) ($data['admin_persen'] ?? 0));
                        $pengembalian = $h['refund'] ?? (float) ($data['refund_utj'] ?? 0);
                        $dasar = $h['dasar'] === 'UTJ' ? $h['utj'] : ($h['dasar'] === 'DP' ? $h['dp'] : $pengembalian);

                        $approval = Approval::create([
                            'tgl_approval' => now(),
                            'jenis' => 'BATAL_KONTRAK',
                            'subject_type' => Kontrak::class,
                            'subject_id' => $record->id,
                            'amount_dampak' => $pengembalian,
                            'alasan' => 'Pembatalan: '.$alasanLabel,
                            'detail_lama' => ['status' => $record->status, 'nilai_kontrak' => (float) $record->total_kontrak],
                            'detail_baru' => [
                                'status_baru' => 'BATAL',
                                'alasan' => $data['alasan'],
                                'alasan_text' => $alasanLabel,
                                'tgl_pembatalan' => $data['tgl_pembatalan'] ?? null,
                                'total_pengembalian' => $pengembalian,
                                // Dasar & potongannya ikut disimpan supaya Refund yang lahir nanti
                                // bisa menyebut angka kotornya, dan supaya pembatalan lama tetap
                                // terbaca meski persen di Pengaturan berubah kemudian.
                                'total_pelunasan' => $dasar,
                                'unrefund' => $h['potongan'],
                                'admin_persen' => $h['admin_persen'],
                                'utj_dibayar' => $h['utj'],
                                'dp_dibayar' => $h['dp'],
                                'dasar' => $h['dasar'],
                            ],
                            'status' => 'DRAFT',
                            'requested_by' => auth()->id(),
                        ]);
                        app(ApprovalService::class)->submit($approval);

                        Notification::make()->success()
                            ->title('Diajukan ke Approval Pembatalan — '.$approval->no_approval)
                            ->body('Kontrak '.$record->no_kontrak.' menunggu persetujuan (2 level). BATAL setelah disetujui.')
                            ->persistent()->send();
                    }),
            ])
            ->defaultSort('tgl_kontrak', 'desc')
            ->paginated([10, 25, 50]);
    }

    /**
     * Isi kolom Refund menurut alasan & persen administrasi.
     *
     * Tidak menyentuh kolomnya sama sekali pada alasan "Lainnya" — di sana angkanya milik
     * operator, dan menimpanya berarti menghapus ketikan orang tepat setelah diketik.
     */
    private static function isiRefund(Kontrak $record, Forms\Get $get, Forms\Set $set): void
    {
        $h = app(HitungPembatalan::class)->pengembalian($record, $get('alasan'), (float) $get('admin_persen'));

        if ($h['refund'] !== null) {
            $set('refund_utj', $h['refund']);
        }
    }

    /** Rincian bertingkat asal angka pengembalian — bentuknya mengikuti lembar rumus. */
    private static function hitunganHtml(Kontrak $record, Forms\Get $get): string
    {
        $h = app(HitungPembatalan::class)->pengembalian($record, $get('alasan'), (float) $get('admin_persen'));
        $rp = fn ($v) => 'Rp '.number_format((float) $v, 0, ',', '.');

        if ($h['dasar'] === 'MANUAL') {
            return '<em style="color:#94a3b8;">Pilih alasan pembatalan untuk melihat hitungannya. Pada alasan "Lainnya", nominalnya diisi manual.</em>';
        }

        $baris = fn ($label, $nilai, $tebal = false) => '<tr>'
            .'<td style="padding:3px 12px 3px 0;">'.($tebal ? '<strong>'.$label.'</strong>' : $label).'</td>'
            .'<td style="padding:3px 0;text-align:right;white-space:nowrap;">'.($tebal ? '<strong>'.$nilai.'</strong>' : $nilai).'</td></tr>';

        $isi = '';
        if ($h['dasar'] === 'UTJ') {
            $isi .= $baris('UTJ yang sudah dibayar', $rp($h['utj']));
            $isi .= $baris('Pengembalian UTJ', $rp($h['refund']), true);
        } else {
            $isi .= $baris('UTJ yang sudah dibayar', $rp($h['utj']).' <span style="color:#b45309;">(hangus)</span>');
            $isi .= $baris('DP yang sudah dibayar', $rp($h['dp']));
            $isi .= $baris('Administrasi '.rtrim(rtrim(number_format($h['admin_persen'], 2, ',', '.'), '0'), ',').'%', '− '.$rp($h['potongan']));
            $isi .= $baris('Pengembalian DP customer', $rp($h['refund']), true);
        }

        $catatan = '';
        if ($h['dasar'] === 'DP') {
            $blt = app(HitungPembatalan::class)->bltBatalSepihak();
            $catatan = '<div style="margin-top:8px;padding-top:8px;border-top:1px solid #334155;color:#94a3b8;">'
                .'BLT Batal Sepihak untuk sales: '.$rp($blt['nilai'])
                .' − PPh '.rtrim(rtrim(number_format($blt['pph_persen'], 2, ',', '.'), '0'), ',').'% '
                .'= <strong style="color:#e2e8f0;">'.$rp($blt['nett']).'</strong> nett. '
                .'Dibuat otomatis (DRAFT) saat pembatalan disetujui.</div>';
        }

        return '<div style="line-height:1.6;"><table style="border-collapse:collapse;">'.$isi.'</table>'.$catatan.'</div>';
    }
}
