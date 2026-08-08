<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Receivable;

use App\Domain\BankLoan\Models\KprRealization;
use App\Domain\BankLoan\Models\KprRetention;
use App\Domain\BusinessSupport\Models\BsArUmCompensation;
use App\Domain\BusinessSupport\Models\BsFakturKeluaranEntry;
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

    public function index(Request $request): Response
    {
        abort_unless($request->user()?->canModule('ar'), 403);

        // Tidak ada penyaringan di server: pencarian, filter per kolom, dan pengurutan
        // seluruhnya dikerjakan di klien atas data lengkap ini.
        $rows = $this->availableRows();

        return Inertia::render('BusinessSupport/Receivable/UmPenjualan', [
            'rows'    => $rows,
            'summary' => [
                'count' => $rows->count(),
                'total' => round((float) $rows->sum('total'), 2),
            ],
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
        ?string $journalNumber, ?string $kprNumber, ?string $note,
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
