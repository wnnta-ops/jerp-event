<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Receivable;

use App\Domain\BankLoan\Models\KprRealization;
use App\Domain\BankLoan\Models\KprRetention;
use App\Domain\BusinessSupport\Models\BsArUmCompensation;
use App\Domain\BusinessSupport\Models\BsFakturKeluaranEntry;
use App\Domain\BusinessSupport\Models\BsJournal;
use App\Domain\BusinessSupport\Models\BsJournalLine;
use App\Domain\BusinessSupport\Services\UangMukaPenjualanLedger;
use App\Domain\Master\Customer\Models\Customer;
use App\Domain\Sales\Booking\Models\Booking;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * UM Penjualan (Account Receivable) — antrean uang muka yang SUDAH disetujui & diposting,
 * siap dikompensasikan dengan piutang customer.
 *
 * Barisnya berasal dari tiga tempat berbeda (lihat BsArUmCompensation::SOURCES):
 *   - Approval Retensi → baris DAJAM      (kpr_retentions, sudah punya journal_id)
 *   - Approval Retensi → baris Pencairan  (kpr_realizations, sudah punya journal_id)
 *   - Approval UM Penjualan (UTJ/DP)      (bs_faktur_keluaran_entries, sudah punya converted_at)
 * "Sudah disetujui & diposting" di aplikasi ini memang ditandai oleh adanya jurnal, bukan oleh
 * kolom approved/posted — tabel-tabel itu tidak punya kolom semacam itu.
 *
 * Aksi "Kompensasi dengan AR" TIDAK memposting jurnal (keputusan pemilik proses): baris hanya
 * berpindah dari layar ini ke Rekonsiliasi Piutang.
 */
class ArUmPenjualanController extends Controller
{
    /** PPN inklusif 11% — sama dengan yang dipakai layar approval asalnya (BankApprovalController). */
    private const PPN_NUM = 11;
    private const PPN_DEN = 111;

    public function index(Request $request, UangMukaPenjualanLedger $ledger): Response
    {
        abort_unless($request->user()?->canModule('ar'), 403);

        // Tidak ada penyaringan di server: pencarian, filter per kolom, dan pengurutan
        // seluruhnya dikerjakan di klien atas data lengkap ini.
        $rows = $this->availableRows();

        // Rentang tanggal SENGAJA kosong secara bawaan: menyaring diam-diam akan menyembunyikan
        // baris buku besar, dan justru itu keluhannya. Detail Transaksi Jurnal punya bawaan
        // "bulan berjalan", jadi tautan ke sana harus membawa rentang yang SEBENARNYA — kalau
        // tidak, baris di luar bulan ini hilang begitu tautannya diklik dan layar tujuan
        // tampak "tidak punya data" padahal datanya ada. Rentang nyata itu dihitung di glRows().
        $from = $request->input('date_from') ?: null;
        $to   = $request->input('date_to') ?: null;

        $gl = $this->glRows($ledger, $from, $to);

        return Inertia::render('BusinessSupport/Receivable/UmPenjualan', [
            'rows'    => $this->denganBukuBesar($rows, $gl['net_per_jurnal']),
            'summary' => [
                'count' => $rows->count(),
                'total' => round((float) $rows->sum('total'), 2),
            ],
            'gl'      => [
                'accounts' => $gl['accounts'],
                'rows'     => $gl['rows'],
                'periode'  => $gl['periode'],
                'filters'  => ['date_from' => $from, 'date_to' => $to],
                // Tab awal ikut dari URL supaya tab Buku Besar bisa ditaut langsung.
                'tab'      => $request->input('tab') === 'buku' ? 'buku' : 'antrean',
            ],
            'rekon'   => $this->rekonsiliasi($rows, $gl),
        ]);
    }

    /**
     * Tandai baris terpilih sebagai sudah dikompensasi dengan AR.
     * Setelah ini barisnya hilang dari layar UM Penjualan dan muncul di Rekonsiliasi Piutang.
     */
    public function compensate(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->canModule('ar'), 403);

        $v = $request->validate([
            'keys'   => ['required', 'array', 'min:1'],
            'keys.*' => ['string', 'max:40'],
        ]);

        // Hanya baris yang memang masih tersedia di antrean yang boleh dikompensasi — mencegah
        // key karangan dari klien dan baris yang sudah dikompensasi lewat tab lain.
        $tersedia = $this->availableRows()->keyBy('row_key');
        $pilih    = collect($v['keys'])->unique()->map(fn ($k) => $tersedia->get($k))->filter()->values();

        if ($pilih->isEmpty()) {
            return back()->with('error', 'Tidak ada baris yang bisa dikompensasi (mungkin sudah dikompensasi sebelumnya).');
        }

        $userId = $request->user()?->id;
        $now    = now();

        DB::transaction(function () use ($pilih, $userId, $now): void {
            foreach ($pilih as $r) {
                // firstOrCreate + unique index (source_type, source_id) → klik ganda / dua petugas
                // menekan bersamaan tidak menghasilkan baris kembar.
                BsArUmCompensation::firstOrCreate(
                    ['source_type' => $r['source_type'], 'source_id' => $r['source_id']],
                    [
                        'booking_id'     => $r['booking_id'],
                        'customer_id'    => $r['customer_id'],
                        'amount'         => $r['total'],
                        'note'           => $r['note'],
                        'compensated_at' => $now,
                        'compensated_by' => $userId,
                    ],
                );
            }
        });

        return back()->with('success', $pilih->count().' UM Penjualan dikompensasi dengan AR — cek di Rekonsiliasi Piutang.');
    }

    /**
     * Batalkan kompensasi — baris kembali ke antrean UM Penjualan.
     *
     * Kompensasi dilakukan dengan mencentang massal, jadi salah centang mudah terjadi; tanpa
     * jalan pulang, kekeliruan itu permanen dan hanya bisa dibetulkan lewat database.
     * Karena kompensasi tidak memposting jurnal, membatalkannya juga tidak menyentuh buku besar.
     */
    public function undo(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->canModule('ar'), 403);

        $v = $request->validate([
            'ids'   => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        // Hard delete, bukan soft delete: unique (source_type, source_id) tidak mengabaikan baris
        // ber-deleted_at, jadi baris tertinggal akan memblokir kompensasi ulang atas sumber yang sama.
        $n = BsArUmCompensation::whereIn('id', $v['ids'])->forceDelete();

        return back()->with(
            $n > 0 ? 'success' : 'error',
            $n > 0
                ? $n.' kompensasi dibatalkan — barisnya kembali ke antrean UM Penjualan.'
                : 'Tidak ada kompensasi yang cocok untuk dibatalkan.'
        );
    }

    // ── Buku besar akun Uang Muka Penjualan ──────────────────────────────────────────────────

    /**
     * Seluruh baris jurnal pada akun Uang Muka Penjualan, lengkap dengan dokumen sumbernya.
     *
     * INI YANG MENYAMBUNGKAN kedua layar: apa pun yang tampil di Detail Transaksi Jurnal untuk
     * akun ini, tampil juga di sini — termasuk yang lahir dari Booked Revenue, top-up kas kecil,
     * dan jurnal memorial manual, yang tak satu pun terwakili oleh tiga tabel sumber antrean.
     *
     * @return array{accounts:array<int,array<string,mixed>>, rows:array<int,array<string,mixed>>, periode:array<string,?string>, net_per_jurnal:array<int,float>, jurnal_antrean:array<int,string>, kolom_hilang:array<int,string>}
     */
    private function glRows(UangMukaPenjualanLedger $ledger, ?string $from, ?string $to): array
    {
        $akun  = $ledger->accounts();
        $lines = $ledger->lines($from, $to);

        $journalIds = $lines->pluck('journal_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
        $dokumen    = $ledger->documentsFor($journalIds);
        $antrean    = $ledger->claimedByQueue($journalIds);

        // Nama customer & SPR diambil sekali untuk semua baris — bukan per baris (N+1).
        $customerIds = collect($dokumen)->pluck('customer_id')->filter()->unique()->values()->all();
        $bookingIds  = collect($dokumen)->pluck('booking_id')->filter()->unique()->values()->all();
        $namaCust    = $customerIds === [] ? collect()
            : Customer::whereIn('id', $customerIds)->pluck('full_name', 'id');
        $spr = $bookingIds === [] ? collect()
            : Booking::whereIn('id', $bookingIds)->with('unit:id,unit_code')->get(['id', 'booking_number', 'unit_id'])->keyBy('id');

        $rows = $lines->map(function (BsJournalLine $l) use ($dokumen, $antrean, $namaCust, $spr): array {
            $j   = $l->journal;
            $doc = $dokumen[(int) $l->journal_id] ?? null;
            $b   = $doc && $doc['booking_id'] ? $spr->get($doc['booking_id']) : null;
            $d   = round((float) $l->debit, 2);
            $k   = round((float) $l->credit, 2);

            return [
                'line_id'        => (int) $l->id,
                'journal_id'     => (int) $l->journal_id,
                'journal_number' => $j?->journal_number,
                'journal_date'   => optional($j?->journal_date)->toDateString(),
                'journal_status' => (string) ($j?->status ?? ''),
                'status_label'   => BsJournal::STATUSES[$j?->status] ?? (string) ($j?->status ?? '—'),
                'journal_source' => (string) ($j?->source ?? ''),
                'reference'      => $j?->reference,
                'account_code'   => $l->account?->code,
                'account_name'   => $l->account?->name,
                'description'    => $l->description ?: $j?->description,
                'debit'          => $d,
                'credit'         => $k,
                // Kredit menambah uang muka, debit menguranginya (mis. saat Booked Revenue
                // membalik uang muka jadi pendapatan). Netto dipakai supaya penjumlahan
                // kolom ini benar-benar sama dengan saldo akunnya di buku besar.
                'net'            => round($k - $d, 2),
                'arah'           => $k >= $d ? 'K' : 'D',
                'doc_type'       => $doc['type'] ?? null,
                'doc_label'      => $doc['label'] ?? null,
                'doc_number'     => $doc['number'] ?? null,
                'customer_id'    => $doc['customer_id'] ?? null,
                'customer'       => ($doc && $doc['customer_id']) ? ($namaCust[$doc['customer_id']] ?? null) : null,
                'spr'            => $b?->booking_number,
                'unit'           => $b?->unit?->unit_code,
                // Terisi = baris ini sudah punya wakilnya di antrean UM Penjualan. Kosong =
                // baris buku besar yang selama ini tidak terlihat di mana pun pada layar ini.
                'queue_key'      => $antrean[(int) $l->journal_id] ?? null,
            ];
        })->sortByDesc('journal_date')->values()->all();

        $tanggal = collect($rows)->pluck('journal_date')->filter()->sort()->values();

        return [
            'accounts'       => $akun->map(fn ($a) => ['id' => (int) $a->id, 'code' => $a->code, 'name' => $a->name])->all(),
            'rows'           => $rows,
            // Rentang NYATA datanya — dipakai tautan ke Detail Transaksi Jurnal supaya
            // bawaan "bulan berjalan" di sana tidak memotong barisnya.
            'periode'        => ['from' => $from ?: $tanggal->first(), 'to' => $to ?: $tanggal->last()],
            'net_per_jurnal' => $ledger->netByJournal($journalIds),
            'jurnal_antrean' => $antrean,
            'kolom_hilang'   => $ledger->kolomHilang(),
        ];
    }

    /**
     * Sandingkan tiap baris antrean dengan nilainya di buku besar.
     *
     * Nilai antrean dan nilai buku besar memang kerap berbeda, dan bukan karena keliru:
     * baris antrean bernilai TOTAL termasuk PPN, sedangkan yang masuk akun Uang Muka hanya
     * DPP-nya. Yang benar-benar patut dicurigai adalah baris antrean yang jurnalnya sama
     * sekali TIDAK menyentuh akun Uang Muka — itu terjadi saat unitnya sudah Booked Revenue
     * dan kreditnya diarahkan ke Piutang Usaha.
     *
     * @param  \Illuminate\Support\Collection<int,array<string,mixed>>  $rows
     * @param  array<int,float>  $netPerJurnal
     * @return \Illuminate\Support\Collection<int,array<string,mixed>>
     */
    private function denganBukuBesar(Collection $rows, array $netPerJurnal): Collection
    {
        return $rows->map(function (array $r) use ($netPerJurnal): array {
            // Dipakai journal_id milik barisnya sendiri, BUKAN peta klaim dari baris buku besar:
            // peta itu hanya berisi jurnal yang memang menyentuh akun Uang Muka, sehingga baris
            // yang kreditnya lari ke Piutang Usaha akan kehilangan tautan jurnalnya — padahal
            // jurnalnya jelas ada dan justru baris itulah yang paling perlu ditelusuri.
            $jid = $r['journal_id'] ?? null;
            $net = $jid !== null ? ($netPerJurnal[$jid] ?? null) : null;

            $r['gl_net'] = $net;
            // false = jurnalnya tidak punya baris pada akun Uang Muka sama sekali.
            $r['gl_ada'] = $net !== null;

            return $r;
        });
    }

    /**
     * Uraian selisih antara antrean UM Penjualan dan saldo akun Uang Muka di buku besar.
     *
     * Tanpa uraian ini kedua angka hanya "berbeda", dan operator tidak punya cara membedakan
     * selisih yang WAJAR (PPN, pembalikan saat Booked Revenue) dari selisih yang berarti ada
     * yang salah posting.
     *
     * @param  \Illuminate\Support\Collection<int,array<string,mixed>>  $rows
     * @param  array<string,mixed>  $gl
     * @return array<string,mixed>
     */
    private function rekonsiliasi(Collection $rows, array $gl): array
    {
        $glRows = collect($gl['rows']);

        $kredit = round((float) $glRows->sum('credit'), 2);
        $debit  = round((float) $glRows->sum('debit'), 2);

        // Baris antrean yang jurnalnya tidak menyentuh akun UM — hampir selalu karena unitnya
        // sudah Booked Revenue sehingga kreditnya ke Piutang Usaha.
        $denganGl = $this->denganBukuBesar($rows, $gl['net_per_jurnal']);
        $tanpaGl  = $denganGl->reject(fn ($r) => $r['gl_ada']);

        // Baris buku besar yang tidak punya wakil di antrean — inilah yang selama ini tidak
        // terlihat di layar UM Penjualan sama sekali.
        $yatim = $glRows->whereNull('queue_key');

        // Baris antrean yang jurnalnya justru MENGURANGI akun Uang Muka. Lazim pada retensi
        // DAJAM (Dr Uang Muka / Cr Bank — uangnya ditahan), padahal di antrean ia tampil
        // sebagai nilai positif yang siap dikompensasi. Arahnya berlawanan, dan tanpa
        // disebut di sini selisihnya tampak seperti kesalahan hitung.
        $berlawanan = $denganGl->filter(fn ($r) => $r['gl_ada'] && (float) $r['gl_net'] < 0);

        return [
            'gl_kredit'      => $kredit,
            'gl_debit'       => $debit,
            'gl_saldo'       => round($kredit - $debit, 2),
            // Jurnal yang belum Approved belum efektif di buku besar, tetapi tetap ditampilkan
            // (Detail Transaksi Jurnal juga menampilkannya). Angkanya dipisah agar terlihat.
            'gl_belum_efektif' => round((float) $glRows->where('journal_status', '!=', 'posted')->sum('net'), 2),
            'antrean_total'  => round((float) $rows->sum('total'), 2),
            'antrean_count'  => $rows->count(),
            'ppn_antrean'    => round((float) $rows->sum('ppn'), 2),
            'tanpa_gl_count' => $tanpaGl->count(),
            'tanpa_gl_total' => round((float) $tanpaGl->sum('total'), 2),
            'lawan_count'    => $berlawanan->count(),
            'lawan_total'    => round((float) $berlawanan->sum('total'), 2),
            'lawan_gl'       => round((float) $berlawanan->sum('gl_net'), 2),
            'yatim_count'    => $yatim->count(),
            'yatim_net'      => round((float) $yatim->sum('net'), 2),
            // Terisi = ada tabel/kolom penaut yang namanya tidak lagi cocok dengan skema,
            // sehingga sebagian baris buku besar kehilangan nomor dokumen & customernya.
            'kolom_hilang'   => $gl['kolom_hilang'],
        ];
    }

    // ── Sumber data ──────────────────────────────────────────────────────────────────────────

    /** Baris UM yang sudah diposting DAN belum dikompensasi. */
    private function availableRows(): Collection
    {
        $sudah = BsArUmCompensation::query()
            ->get(['source_type', 'source_id'])
            ->map(fn ($c) => $c->source_type.'-'.$c->source_id)
            ->flip();

        return $this->sourceRows()->reject(fn (array $r) => $sudah->has($r['row_key']))->values();
    }

    /** Gabungan tiga sumber, sudah dinormalkan ke satu bentuk baris. */
    private function sourceRows(): Collection
    {
        return collect()
            ->concat($this->retensiRows())
            ->concat($this->pencairanRows())
            ->concat($this->utjDpRows())
            ->sortByDesc('doc_date')
            ->values();
    }

    /** Approval Retensi — baris DAJAM yang sudah dijurnal. */
    private function retensiRows(): Collection
    {
        return KprRetention::query()
            ->whereNotNull('journal_id')
            ->with([
                'realization.application.customer:id,full_name',
                'realization.application.booking:id,booking_number,unit_id',
                'realization.application.booking.unit:id,unit_code',
                'journal:id,journal_number,journal_date',
            ])
            ->get()
            ->map(function (KprRetention $r) {
                $app     = $r->realization?->application;
                $booking = $app?->booking;
                $total   = round((float) $r->amount, 2);

                return $this->row(
                    'retensi', $r->id, $total, $booking?->id, $app?->customer_id,
                    $app?->customer?->full_name, $booking?->booking_number, $booking?->unit?->unit_code,
                    optional($r->released_at ?? $r->realization?->disbursed_at)->toDateString(),
                    $r->journal_id ? (int) $r->journal_id : null,
                    $r->journal?->journal_number, $app?->application_number, $r->claim_reason,
                );
            });
    }

    /** Approval Retensi — baris pencairan KPR yang sudah dijurnal (net = cair − retensi). */
    private function pencairanRows(): Collection
    {
        return KprRealization::query()
            ->whereNotNull('journal_id')
            ->whereRaw('(disbursed_amount - COALESCE(retention_amount, 0)) > 0.009')
            ->with([
                'application.customer:id,full_name',
                'application.booking:id,booking_number,unit_id',
                'application.booking.unit:id,unit_code',
                'journal:id,journal_number,journal_date',
            ])
            ->get()
            ->map(function (KprRealization $r) {
                $app     = $r->application;
                $booking = $app?->booking;
                $total   = round((float) $r->disbursed_amount - (float) $r->retention_amount, 2);

                return $this->row(
                    'pencairan', $r->id, $total, $booking?->id, $app?->customer_id,
                    $app?->customer?->full_name, $booking?->booking_number, $booking?->unit?->unit_code,
                    optional($r->disbursed_at)->toDateString(),
                    $r->journal_id ? (int) $r->journal_id : null,
                    $r->journal?->journal_number, $app?->application_number, null,
                );
            });
    }

    /** Approval UM Penjualan (UTJ/DP) yang sudah dikonversi jadi Uang Muka. */
    private function utjDpRows(): Collection
    {
        return BsFakturKeluaranEntry::query()
            ->where('source_type', 'utj_dp')
            ->whereNotNull('converted_at')
            ->with([
                'customer:id,full_name',
                'booking:id,booking_number,unit_id',
                'booking.unit:id,unit_code',
                'convertJournal:id,journal_number,journal_date',
            ])
            ->get()
            ->map(fn (BsFakturKeluaranEntry $e) => $this->row(
                'utj_dp', $e->id, round((float) $e->total, 2), $e->booking_id, $e->customer_id,
                $e->customer?->full_name, $e->booking?->booking_number, $e->booking?->unit?->unit_code,
                optional($e->doc_date)->toDateString(),
                $e->convert_journal_id ? (int) $e->convert_journal_id : null,
                $e->convertJournal?->journal_number, null, $e->keterangan,
                // Entri UTJ/DP menyimpan pecahan DPP/PPN-nya sendiri — pakai angka itu, jangan
                // dihitung ulang. Konversi lewat jalur fallback bisa menyimpan ppn = 0, dan
                // menghitung ulang 11/111 akan menampilkan PPN yang tidak ada di buku besar.
                round((float) $e->dpp, 2), round((float) $e->ppn, 2),
            ));
    }

    /**
     * Bentuk baris seragam untuk ketiga sumber.
     *
     * Nilai yang dipakai untuk kompensasi adalah TOTAL (termasuk PPN), karena piutang di AR juga
     * dicatat termasuk PPN — membandingkan total dengan total.
     *
     * DPP & PPN hanya rincian tampilan. Bila sumbernya menyimpan pecahan itu (entri UTJ/DP),
     * angka tersimpan yang dipakai. Untuk retensi & pencairan tidak ada pecahan tersimpan, jadi
     * dipakai perkiraan PPN inklusif 11% — sama dengan pratinjau di layar approval asalnya.
     *
     * @return array<string, mixed>
     */
    private function row(
        string $sourceType, int $sourceId, float $total, ?int $bookingId, ?int $customerId,
        ?string $customer, ?string $spr, ?string $unit, ?string $docDate,
        ?int $journalId, ?string $journalNumber, ?string $kprNumber, ?string $note,
        ?float $dppTersimpan = null, ?float $ppnTersimpan = null,
    ): array {
        // Pecahan tersimpan dipakai apa adanya HANYA bila konsisten dengan total; kalau tidak,
        // menampilkannya justru menyesatkan (angka tak pernah menjumlah di layar).
        $pakaiTersimpan = $dppTersimpan !== null && $ppnTersimpan !== null
            && abs(($dppTersimpan + $ppnTersimpan) - $total) < 0.01;

        $ppn = $pakaiTersimpan ? $ppnTersimpan : round($total * self::PPN_NUM / self::PPN_DEN, 2);

        return [
            'row_key'        => $sourceType.'-'.$sourceId,
            'source_type'    => $sourceType,
            'source_label'   => BsArUmCompensation::SOURCES[$sourceType] ?? $sourceType,
            'source_id'      => $sourceId,
            'booking_id'     => $bookingId,
            'customer_id'    => $customerId,
            'customer'       => $customer,
            'spr'            => $spr,
            'unit'           => $unit,
            'doc_date'       => $docDate,
            'journal_id'     => $journalId,
            'journal_number' => $journalNumber,
            'kpr_number'     => $kprNumber,
            'note'           => $note,
            'dpp'            => round($total - $ppn, 2),
            'ppn'            => round($ppn, 2),
            'ppn_pasti'      => $pakaiTersimpan,   // false = perkiraan 11/111, bukan angka buku besar
            'total'          => $total,
        ];
    }
}
