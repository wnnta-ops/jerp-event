<?php

namespace App\Filament\Fo\Pages;

use App\Filament\Concerns\MematuhiIzinHalaman;
use App\Filament\Fo\Resources\RefundResource;
use App\Modules\BO\IproBridge\Services\RekomBatalCashService;
use App\Modules\FO\Approval\Models\Approval;
use App\Modules\FO\Approval\Services\ApprovalService;
use App\Modules\FO\MarketingSales\Models\Kontrak;
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
                        Forms\Components\Radio::make('alasan')->label('Alasan Pembatalan')->required()->live()
                            ->afterStateUpdated(function ($state, Forms\Get $get, Forms\Set $set) {
                                // Reject Bank / Kol 5 / Tutup Buku → kembalikan SEMUA yang sudah dibayar customer (− admin).
                                // Batal Sepihak → 0 (hangus). Lainnya → diisi manual.
                                if (in_array($state, ['REJECT_BANK', 'KOL_5', 'TUTUP_BUKU'], true)) {
                                    $set('refund_utj', (float) $get('paid_net'));
                                } elseif ($state === 'BATAL_SEPIHAK') {
                                    $set('refund_utj', 0);
                                }
                            })
                            ->options([
                                'BATAL_SEPIHAK' => 'Batal Sepihak',
                                'REJECT_BANK' => 'Reject Bank',
                                'KOL_5' => 'Kol 5',
                                'TUTUP_BUKU' => 'Tutup Buku',
                                'LAINNYA' => 'Lainnya',
                            ]),
                        Forms\Components\Textarea::make('alasan_lainnya')->label('Alasan Lainnya')->rows(2)
                            ->visible(fn (Forms\Get $get) => $get('alasan') === 'LAINNYA')
                            ->required(fn (Forms\Get $get) => $get('alasan') === 'LAINNYA'),
                        Forms\Components\DatePicker::make('tgl_pembatalan')->label('Tgl Pembatalan')->default(now())->required(),
                        Forms\Components\Hidden::make('paid_net')->default(function (Kontrak $record) {
                            [$total, $admin] = RefundResource::paidTotals((int) $record->id);

                            return max(0, $total - $admin);
                        }),
                        Forms\Components\TextInput::make('refund_utj')->label('Refund (dari yang sudah dibayar)')->numeric()->rupiah()->default(0)
                            ->readOnly(fn (Forms\Get $get) => $get('alasan') !== 'LAINNYA')
                            ->helperText('Reject Bank / Kol 5 / Tutup Buku: otomatis = TOTAL yang sudah dibayar customer (− admin). Batal Sepihak: 0 (hangus). Lainnya: isi manual. Pencairan di menu Finance → Refund.'),
                        Forms\Components\Placeholder::make('rincian')->label('Rincian yang sudah dibayar customer')
                            ->content(fn (Kontrak $record) => new HtmlString(RefundResource::rincianHtml((int) $record->id))),
                    ])
                    ->action(function (Kontrak $record, array $data) {
                        $alasanLabel = $data['alasan'] === 'LAINNYA'
                            ? ($data['alasan_lainnya'] ?? 'Lainnya')
                            : str_replace('_', ' ', $data['alasan']);

                        $approval = Approval::create([
                            'tgl_approval' => now(),
                            'jenis' => 'BATAL_KONTRAK',
                            'subject_type' => Kontrak::class,
                            'subject_id' => $record->id,
                            'amount_dampak' => (float) ($data['refund_utj'] ?? 0),
                            'alasan' => 'Pembatalan: '.$alasanLabel,
                            'detail_lama' => ['status' => $record->status, 'nilai_kontrak' => (float) $record->total_kontrak],
                            'detail_baru' => [
                                'status_baru' => 'BATAL',
                                'alasan' => $data['alasan'],
                                'alasan_text' => $alasanLabel,
                                'tgl_pembatalan' => $data['tgl_pembatalan'] ?? null,
                                'total_pengembalian' => (float) ($data['refund_utj'] ?? 0),
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
}
