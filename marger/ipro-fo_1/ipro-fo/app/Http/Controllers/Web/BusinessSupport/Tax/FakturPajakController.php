<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Tax;

use App\Domain\BusinessSupport\Actions\ConvertUtjDpToUangMuka;
use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsApInvoice;
use App\Domain\BusinessSupport\Models\BsArInvoice;
use App\Domain\BusinessSupport\Models\BsFakturKeluaranEntry;
use App\Domain\BusinessSupport\Models\BsFakturPajakLineEntry;
use App\Domain\BusinessSupport\Models\BsJournal;
use App\Domain\BusinessSupport\Services\JournalPartyResolver;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Faktur Pajak — daftar PPN Keluaran & PPN Masukan untuk SPT Masa PPN / e-Faktur.
 *
 * KEDUA DAFTARNYA DIBENTUK DARI MUTASI AKUN PPN DI BUKU BESAR, bukan dari tabel invoice:
 *   PPN Keluaran  → akun 20104040406 (sisi KREDIT)
 *   PPN Masukan   → akun 10105010101 (sisi DEBIT)
 *   PPN Kurang/(Lebih) Bayar = Keluaran − Masukan
 *
 * Alasannya sama untuk keduanya: PPN bisa lahir dari jalur yang tidak berinvoice — jurnal
 * memorial, koreksi, impor jurnal, konversi UTJ/DP, pelepasan DAJAM. Selama daftarnya dibaca
 * dari tabel invoice, semua itu tidak pernah muncul walaupun akun PPN-nya jelas termutasi, dan
 * SPT disusun dari daftar yang lebih pendek daripada buku besarnya sendiri — tanpa satu galat
 * pun yang memberi tahu. Satu baris di sini = satu BARIS JURNAL, jadi tidak ada yang terlewat.
 */
class FakturPajakController extends Controller
{
    /** Label sumber baris: sumber jurnal apa adanya + entri approval yang belum berjurnal. */
    public const SUMBER = BsJournal::SOURCES + [
        'retensi'         => 'Approval Retensi',
        'retensi_release' => 'Pelepasan DAJAM',
        'dajam'           => 'Pelepasan DAJAM',
        'pencairan'       => 'Approval Pencairan',
        'utj_dp'          => 'Approval UM Penjualan',
    ];

    /** Kolom berkas impor — urutannya juga urutan kolom pada format yang diunduh. */
    public const KOLOM_IMPOR = ['JENIS_BARIS', 'ID_BARIS', 'NO_JURNAL_DOKUMEN', 'TANGGAL', 'NAMA', 'DPP', 'PPN', 'NO_FAKTUR_PAJAK', 'NPWP'];

    /** Faktur Pajak Keluaran — PPN atas penjualan (akun 20104040406). */
    public function keluaran(Request $request): Response
    {
        abort_unless($request->user()?->can('tax.ppn-keluaran.view'), 403);
        [$year, $month] = $this->period($request);

        $rows = $this->keluaranRows($year, $month);
        $belum = $rows->filter(fn (array $r) => ($r['tax_invoice_no'] ?? null) === null);

        return Inertia::render('BusinessSupport/Tax/Keluaran', [
            'rows'    => $rows->values(),
            'period'  => ['year' => $year, 'month' => $month],
            'sources' => self::SUMBER,
            'totals'  => [
                'dpp' => round($rows->sum('dpp'), 2), 'ppn' => round($rows->sum('ppn'), 2), 'count' => $rows->count(),
                // Yang paling ingin dilihat orang saat menutup masa pajak bukan pecahan per
                // sumber, melainkan berapa baris yang nomor fakturnya masih kosong.
                'tanpa_fp_count' => $belum->count(), 'tanpa_fp_ppn' => round($belum->sum('ppn'), 2),
                'jurnal_count'   => $rows->where('row_type', 'jurnal')->count(),
                'entry_count'    => $rows->where('row_type', 'entry')->count(),
            ],
        ]);
    }

    /**
     * Baris Faktur Pajak Keluaran satu masa pajak — DARI MUTASI AKUN PPN KELUARAN.
     *
     * Isinya dua kelompok, dan kelompok kedua sengaja tidak dihapus:
     *   1. BARIS JURNAL yang mengkredit akun PPN Keluaran — daftar yang tidak bisa bocor.
     *   2. Entri Approval Retensi / UTJ & DP yang BELUM punya jurnal PPN Keluaran. Entri UTJ & DP
     *      memang belum berjurnal sampai dikonversi ke Uang Muka Penjualan, dan tombol konversi
     *      itu hanya ada di layar ini. Menghapus kelompok ini demi "semuanya dari jurnal" akan
     *      menghilangkan satu-satunya pintu konversinya.
     *
     * Entri yang jurnalnya SUDAH ada di kelompok 1 (baik journal_id maupun convert_journal_id)
     * dikeluarkan dari kelompok 2 — kalau tidak, satu PPN yang sama terhitung dua kali.
     *
     * @return Collection<int,array<string,mixed>>
     */
    private function keluaranRows(int $year, int $month): Collection
    {
        $akunPpn = BsAccount::where('code', config('business_support.gl_accounts.ppn_out'))->value('id');

        $baris = collect();
        $journalIds = [];

        if ($akunPpn) {
            $baris = DB::table('bs_journal_lines as jl')
                ->join('bs_journals as j', 'j.id', '=', 'jl.journal_id')
                ->where('jl.account_id', $akunPpn)
                ->where('jl.credit', '>', 0)
                ->whereNull('j.deleted_at')
                ->whereYear('j.journal_date', $year)->whereMonth('j.journal_date', $month)
                ->orderBy('j.journal_date')->orderBy('jl.id')
                ->get(['jl.id', 'jl.journal_id', 'jl.credit', 'jl.description as line_desc',
                    'j.journal_number', 'j.journal_date', 'j.reference', 'j.source', 'j.description as journal_desc']);
            $journalIds = $baris->pluck('journal_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
        }

        $rows = collect();

        if ($journalIds !== []) {
            /* DPP diturunkan dari jurnalnya: total KREDIT jurnal dikurangi seluruh kredit pada akun
               PPN Keluaran. Pada jurnal penjualan (Dr Piutang / Cr Penjualan + Cr PPN Keluaran),
               sisanya persis dasar pengenaan pajaknya. Diturunkan, bukan diambil dari invoice,
               supaya baris yang tidak berinvoice tetap punya DPP — bukan nol yang menyesatkan. */
            $totalKredit = DB::table('bs_journal_lines')->whereIn('journal_id', $journalIds)
                ->selectRaw('journal_id, COALESCE(SUM(credit),0) c')->groupBy('journal_id')->pluck('c', 'journal_id');
            $ppnKredit = DB::table('bs_journal_lines')->whereIn('journal_id', $journalIds)
                ->where('account_id', $akunPpn)
                ->selectRaw('journal_id, COALESCE(SUM(credit),0) c')->groupBy('journal_id')->pluck('c', 'journal_id');

            // Invoice piutang penerbit jurnal — sumber nomor dokumen & nama pelanggan terbaik.
            $arInvoice = BsArInvoice::with('customer:id,full_name,npwp_name')
                ->whereIn('journal_id', $journalIds)
                ->get(['id', 'journal_id', 'invoice_number', 'customer_id', 'dpp'])
                ->keyBy('journal_id');

            // Entri approval yang jurnalnya ada di daftar — sumber kode unit & nama pelanggan.
            $entriApproval = [];
            BsFakturKeluaranEntry::with(['customer:id,full_name,npwp_name', 'booking.unit:id,unit_code'])
                ->where(fn ($q) => $q->whereIn('journal_id', $journalIds)->orWhereIn('convert_journal_id', $journalIds))
                ->get()
                ->each(function ($e) use (&$entriApproval): void {
                    foreach ([$e->journal_id, $e->convert_journal_id] as $jid) {
                        if ($jid && ! isset($entriApproval[(int) $jid])) {
                            $entriApproval[(int) $jid] = $e;
                        }
                    }
                });

            // Cadangan nama pihak untuk jurnal yang tidak berinvoice (memorial, impor, koreksi).
            $pihak = app(JournalPartyResolver::class)->forJournalIds($journalIds);

            // Nomor faktur & NPWP yang sudah diisi — kosong berarti memang belum diisi.
            $terisi = BsFakturPajakLineEntry::where('jenis', BsFakturPajakLineEntry::KELUARAN)
                ->whereIn('journal_line_id', $baris->pluck('id'))->get()->keyBy('journal_line_id');

            $rows = $baris->map(function ($r) use ($totalKredit, $ppnKredit, $arInvoice, $entriApproval, $pihak, $terisi) {
                $inv = $arInvoice[$r->journal_id] ?? null;
                $ent = $entriApproval[(int) $r->journal_id] ?? null;
                $e = $terisi[$r->id] ?? null;
                $unit = $ent?->booking?->unit?->unit_code;

                $dppJurnal = round((float) ($totalKredit[$r->journal_id] ?? 0) - (float) ($ppnKredit[$r->journal_id] ?? 0), 2);

                return [
                    // Identitas baris = BARIS JURNAL-nya, bukan invoice: itulah yang selalu ada.
                    'row_type'       => 'jurnal',
                    'id'             => (int) $r->id,
                    'journal_id'     => (int) $r->journal_id,
                    'journal_number' => $r->journal_number,
                    'source'         => $r->source,
                    'invoice_date'   => $r->journal_date ? substr((string) $r->journal_date, 0, 10) : null,
                    'partner'        => $inv?->customer?->npwp_name ?: $inv?->customer?->full_name
                        ?: ($ent?->customer?->npwp_name ?: $ent?->customer?->full_name)
                        ?: ($pihak[$r->journal_id] ?? null) ?: ($r->journal_desc ?: '—'),
                    'doc_no'         => $inv?->invoice_number ?: $unit ?: ($r->reference ?: $r->journal_number),
                    'unit'           => $unit,
                    'keterangan'     => $r->line_desc ?: $r->journal_desc,
                    // DPP dari invoice bila ada (angka resminya), selainnya turunan dari jurnalnya.
                    'dpp'            => $inv && (float) $inv->dpp > 0 ? (float) $inv->dpp : $dppJurnal,
                    'ppn'            => (float) $r->credit,
                    // Sengaja KOSONG sampai diisi — satuan maupun massal.
                    'tax_invoice_no' => $e?->fp_number,
                    'npwp'           => $e?->npwp,
                    'has_entry'      => $e !== null,
                    'editable'       => true,
                    'needs_konversi' => false,
                ];
            });
        }

        // Kelompok 2: entri approval yang belum berjurnal PPN Keluaran pada masa ini.
        $sisaEntri = BsFakturKeluaranEntry::with(['customer:id,full_name,npwp_name', 'booking.unit:id,unit_code'])
            ->whereYear('doc_date', $year)->whereMonth('doc_date', $month)
            ->orderBy('doc_date')->orderBy('id')->get()
            ->reject(fn ($e) => in_array((int) $e->journal_id, $journalIds, true)
                || in_array((int) $e->convert_journal_id, $journalIds, true))
            ->map(fn ($e) => [
                'row_type'       => 'entry',
                'id'             => (int) $e->id,
                'journal_id'     => null,
                'journal_number' => null,
                'source'         => $e->source_type,
                'invoice_date'   => $e->doc_date?->format('Y-m-d'),
                'partner'        => $e->customer?->npwp_name ?: ($e->customer?->full_name ?? '—'),
                'doc_no'         => $e->booking?->unit?->unit_code ?: $e->keterangan,
                'unit'           => $e->booking?->unit?->unit_code,
                'keterangan'     => $e->keterangan,
                'dpp'            => (float) $e->dpp,
                'ppn'            => (float) $e->ppn,
                'tax_invoice_no' => $e->fp_number,
                'npwp'           => null,
                'has_entry'      => $e->fp_number !== null,
                // UTJ & DP baru boleh bernomor faktur setelah dikonversi ke Uang Muka Penjualan.
                'editable'       => (bool) $e->is_active,
                'needs_konversi' => $e->source_type === 'utj_dp' && $e->converted_at === null,
            ])->values();

        return $rows->concat($sisaEntri)->sortBy('invoice_date')->values();
    }

    /** Faktur Pajak Masukan — PPN atas pembelian (akun 10105010101). */
    public function masukan(Request $request): Response
    {
        abort_unless($request->user()?->can('tax.ppn-masukan.view'), 403);
        [$year, $month] = $this->period($request);

        $rows = $this->masukanRows($year, $month);

        return Inertia::render('BusinessSupport/Tax/Masukan', [
            'rows'    => $rows,
            'period'  => ['year' => $year, 'month' => $month],
            'totals'  => ['dpp' => round($rows->sum('dpp'), 2), 'ppn' => round($rows->sum('ppn'), 2), 'count' => $rows->count()],
        ]);
    }

    /**
     * Baris Faktur Pajak Masukan satu masa pajak — DARI MUTASI AKUN PPN MASUKAN di buku besar.
     * Alasan lengkapnya ada pada docblock kelas ini.
     *
     * @return Collection<int,array<string,mixed>>
     */
    private function masukanRows(int $year, int $month): Collection
    {
        $akunPpn = BsAccount::where('code', config('business_support.gl_accounts.ppn_in'))->value('id');
        if (! $akunPpn) {
            return collect();
        }

        $baris = DB::table('bs_journal_lines as jl')
            ->join('bs_journals as j', 'j.id', '=', 'jl.journal_id')
            ->where('jl.account_id', $akunPpn)
            ->where('jl.debit', '>', 0)
            ->whereNull('j.deleted_at')
            ->whereYear('j.journal_date', $year)->whereMonth('j.journal_date', $month)
            ->orderBy('j.journal_date')->orderBy('jl.id')
            ->get(['jl.id', 'jl.journal_id', 'jl.debit', 'jl.description as line_desc',
                'j.journal_number', 'j.journal_date', 'j.reference', 'j.description as journal_desc']);

        if ($baris->isEmpty()) {
            return collect();
        }

        $journalIds = $baris->pluck('journal_id')->map(fn ($v) => (int) $v)->unique()->values()->all();

        /* DPP diturunkan dari jurnalnya: total DEBIT jurnal dikurangi seluruh debit pada akun
           PPN Masukan. Pada jurnal pembelian (Dr Beban/Aset + Dr PPN Masukan / Cr Utang), sisanya
           persis dasar pengenaan pajaknya. */
        $totalDebit = DB::table('bs_journal_lines')->whereIn('journal_id', $journalIds)
            ->selectRaw('journal_id, COALESCE(SUM(debit),0) d')->groupBy('journal_id')->pluck('d', 'journal_id');
        $ppnDebit = DB::table('bs_journal_lines')->whereIn('journal_id', $journalIds)
            ->where('account_id', $akunPpn)
            ->selectRaw('journal_id, COALESCE(SUM(debit),0) d')->groupBy('journal_id')->pluck('d', 'journal_id');

        // Invoice hutang yang menerbitkan jurnal itu — sumber No. Inv. Supplier & nama supplier
        // yang paling tepat bila memang ada.
        $invoice = BsApInvoice::with('supplier:id,name')
            ->whereIn('journal_id', $journalIds)->get(['id', 'journal_id', 'supplier_id', 'supplier_invoice_no', 'dpp'])
            ->keyBy('journal_id');

        // Cadangan nama pihak untuk jurnal yang tidak berinvoice (memorial, impor, koreksi).
        $pihak = app(JournalPartyResolver::class)->forJournalIds($journalIds);

        // Nomor faktur & NPWP yang sudah diisi — kosong berarti memang belum diisi.
        $entri = BsFakturPajakLineEntry::where('jenis', BsFakturPajakLineEntry::MASUKAN)
            ->whereIn('journal_line_id', $baris->pluck('id'))->get()->keyBy('journal_line_id');

        return $baris->map(function ($r) use ($totalDebit, $ppnDebit, $invoice, $pihak, $entri) {
            $inv = $invoice[$r->journal_id] ?? null;
            $e = $entri[$r->id] ?? null;

            $dppJurnal = round((float) ($totalDebit[$r->journal_id] ?? 0) - (float) ($ppnDebit[$r->journal_id] ?? 0), 2);

            return [
                // Identitas baris = BARIS JURNAL-nya, bukan invoice: itulah yang selalu ada.
                'id'                  => (int) $r->id,
                'journal_id'          => (int) $r->journal_id,
                'invoice_number'      => $r->journal_number,
                'reference'           => $r->reference,
                'invoice_date'        => $r->journal_date ? substr((string) $r->journal_date, 0, 10) : null,
                'supplier_invoice_no' => $inv?->supplier_invoice_no,
                'partner'             => $inv?->supplier?->name ?: ($pihak[$r->journal_id] ?? null) ?: ($r->journal_desc ?: '—'),
                'keterangan'          => $r->line_desc ?: $r->journal_desc,
                'dpp'                 => $inv && (float) $inv->dpp > 0 ? (float) $inv->dpp : $dppJurnal,
                'ppn'                 => (float) $r->debit,
                // Sengaja KOSONG sampai diisi — satuan maupun massal.
                'tax_invoice_no'      => $e?->fp_number,
                'npwp'                => $e?->npwp,
                'has_entry'           => $e !== null,
            ];
        });
    }

    /** Rekap PPN periode — Keluaran − Masukan = Kurang/(Lebih) Bayar, dengan cross-check GL. */
    public function rekap(Request $request): Response
    {
        abort_unless($request->user()?->can('tax.rekap'), 403);
        [$year, $month] = $this->period($request);

        /* Angkanya dibaca dari DAFTAR YANG SAMA dengan kedua layarnya. Sebelumnya rekap ini
           menjumlah tabel invoice AR/AP sementara layarnya membaca mutasi akun PPN, jadi ketiga
           halaman bisa menyebut tiga angka berbeda untuk masa pajak yang sama. */
        $kel = $this->keluaranRows($year, $month);
        $mas = $this->masukanRows($year, $month);

        $keluaran = (float) $kel->sum('ppn');
        $masukan  = (float) $mas->sum('ppn');

        // Cross-check GL: mutasi akun PPN periode dari jurnal posted.
        $glOut = $this->accPeriodMutation(config('business_support.gl_accounts.ppn_out'), $year, $month); // kredit − debit
        $glIn  = $this->accPeriodMutation(config('business_support.gl_accounts.ppn_in'), $year, $month, true); // debit − credit

        return Inertia::render('BusinessSupport/Tax/Rekap', [
            'period'   => ['year' => $year, 'month' => $month],
            'keluaran' => [
                'dpp' => round((float) $kel->sum('dpp'), 2), 'ppn' => round($keluaran, 2),
                'jurnal_ppn' => round((float) $kel->where('row_type', 'jurnal')->sum('ppn'), 2),
                'entry_ppn'  => round((float) $kel->where('row_type', 'entry')->sum('ppn'), 2),
            ],
            'masukan'  => ['dpp' => round((float) $mas->sum('dpp'), 2), 'ppn' => round($masukan, 2)],
            'selisih'  => round($keluaran - $masukan, 2), // + = kurang bayar (setor), − = lebih bayar (kompensasi)
            'gl'       => ['keluaran' => round($glOut, 2), 'masukan' => round($glIn, 2)],
        ]);
    }

    /** Set / ubah Nomor Faktur Pajak + NPWP satu BARIS JURNAL (masukan maupun keluaran). */
    public function updateNsfp(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('tax.nsfp'), 403);
        $v = $request->validate([
            'type'           => ['required', 'in:keluaran,masukan'],
            'invoice_id'     => ['required', 'integer'],   // id BARIS JURNAL
            'tax_invoice_no' => ['nullable', 'string', 'max:30'],
            'npwp'           => ['sometimes', 'nullable', 'string', 'max:30'],
        ]);

        /* Nomornya menempel pada BARIS JURNAL, bukan pada invoice: daftarnya dibentuk dari mutasi
           akun PPN, dan sebagian barisnya memang tidak punya invoice. NPWP-nya pun disimpan pada
           entri, TIDAK dituliskan balik ke master supplier/customer — NPWP pada faktur adalah yang
           tertera di dokumen itu, dan menimpakan master akan mengubah NPWP seluruh faktur lain
           milik pihak yang sama, termasuk yang sudah dilaporkan. */
        $this->simpanEntriBaris($v['type'], (int) $v['invoice_id'], $v['tax_invoice_no'] ?? null,
            $request->has('npwp') ? ($v['npwp'] ?? null) : null, $request->user()?->id);

        return back()->with('success', 'Nomor Faktur Pajak & NPWP diperbarui.');
    }

    /**
     * Simpan (atau bersihkan) nomor faktur & NPWP satu baris jurnal PPN.
     *
     * Entrinya DIHAPUS bila keduanya kosong, bukan disimpan sebagai baris bernilai null: baris
     * kosong yang menetap membuat "sudah pernah diisi lalu dikosongkan" tidak terbedakan dari
     * "memang belum pernah diisi", padahal keduanya berarti hal berbeda saat merekonsiliasi.
     */
    private function simpanEntriBaris(string $jenis, int $journalLineId, ?string $fp, ?string $npwp, ?int $userId): void
    {
        $line = DB::table('bs_journal_lines')->where('id', $journalLineId)->first(['id', 'journal_id']);
        if (! $line) {
            return;
        }

        $fp = trim((string) $fp) ?: null;
        $npwp = trim((string) $npwp) ?: null;

        if ($fp === null && $npwp === null) {
            BsFakturPajakLineEntry::where('journal_line_id', $journalLineId)->delete();

            return;
        }

        BsFakturPajakLineEntry::updateOrCreate(
            ['journal_line_id' => $journalLineId],
            ['jenis' => $jenis, 'journal_id' => (int) $line->journal_id, 'fp_number' => $fp, 'npwp' => $npwp, 'created_by' => $userId],
        );
    }

    /** Hapus nomor faktur & NPWP satu baris — barisnya sendiri TETAP ada (ia milik buku besar). */
    public function hapusEntriBaris(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('tax.nsfp'), 403);
        $v = $request->validate([
            'row_type'        => ['sometimes', 'in:jurnal,entry'],
            'journal_line_id' => ['required', 'integer'],
        ]);

        // Baris entri approval (Retensi / UTJ & DP) belum berjurnal — nomornya tersimpan di
        // entrinya sendiri, jadi yang dikosongkan kolom fp_number-nya.
        if (($v['row_type'] ?? 'jurnal') === 'entry') {
            BsFakturKeluaranEntry::where('id', (int) $v['journal_line_id'])->update(['fp_number' => null]);
        } else {
            BsFakturPajakLineEntry::where('journal_line_id', (int) $v['journal_line_id'])->delete();
        }

        return back()->with('success', 'Data faktur pajak baris ini dihapus — barisnya tetap ada dan siap diisi ulang.');
    }

    /** Input massal NSFP + NPWP untuk baris terpilih pada Faktur Pajak Masukan. */
    public function bulkNsfp(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('tax.nsfp'), 403);
        $v = $request->validate([
            'items'                  => ['required', 'array'],
            'items.*.invoice_id'     => ['required', 'integer'],
            'items.*.tax_invoice_no' => ['nullable', 'string', 'max:30'],
            'items.*.npwp'           => ['nullable', 'string', 'max:30'],
        ]);

        [$baris, $berNpwp] = $this->simpanMassalBaris(BsFakturPajakLineEntry::MASUKAN, $v['items'], $request->user()?->id);

        return back()->with('success', "Tersimpan: {$baris} baris faktur masukan, {$berNpwp} di antaranya beserta NPWP.");
    }

    /**
     * Simpan sekumpulan baris jurnal sekaligus.
     *
     * @param  array<int,array<string,mixed>>  $items
     * @return array{0:int,1:int}  [jumlah baris tersimpan, di antaranya yang ber-NPWP]
     */
    private function simpanMassalBaris(string $jenis, array $items, ?int $userId): array
    {
        $baris = 0;
        $berNpwp = 0;

        DB::transaction(function () use ($jenis, $items, $userId, &$baris, &$berNpwp): void {
            foreach ($items as $item) {
                $fp = ($item['tax_invoice_no'] ?? null) ?: null;
                $npwp = array_key_exists('npwp', $item) ? (($item['npwp'] ?? null) ?: null) : null;
                if ($fp === null && $npwp === null) {
                    continue;   // baris yang memang dikosongkan tidak perlu diproses
                }

                $this->simpanEntriBaris($jenis, (int) $item['invoice_id'], $fp, $npwp, $userId);
                $baris++;
                if ($npwp !== null) {
                    $berNpwp++;
                }
            }
        });

        return [$baris, $berNpwp];
    }

    /** Set / ubah Nomor Faktur Pajak pada satu entri Retensi / UTJ & DP (harus sudah aktif). */
    public function updateEntryNsfp(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('tax.nsfp'), 403);
        $v = $request->validate([
            'entry_id'       => ['required', 'integer'],
            'tax_invoice_no' => ['nullable', 'string', 'max:30'],
        ]);

        $entry = BsFakturKeluaranEntry::findOrFail($v['entry_id']);
        if (! $entry->is_active) {
            return back()->with('error', 'Nomor Faktur Pajak belum bisa diisi — konversi UTJ & DP ke Uang Muka Penjualan terlebih dahulu.');
        }
        $entry->update(['fp_number' => $v['tax_invoice_no'] ?: null]);

        return back()->with('success', 'Nomor Faktur Pajak diperbarui.');
    }

    /** Input massal FP & NPWP Faktur Pajak Keluaran — baris jurnal maupun entri approval. */
    public function bulkKeluaranNsfp(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('tax.nsfp'), 403);
        $v = $request->validate([
            'items'                  => ['required', 'array', 'min:1'],
            'items.*.row_type'       => ['required', 'in:jurnal,entry'],
            'items.*.id'             => ['required', 'integer'],
            'items.*.tax_invoice_no' => ['nullable', 'string', 'max:30'],
            'items.*.npwp'           => ['nullable', 'string', 'max:30'],
        ]);

        [$saved, $skipped] = $this->simpanMassalKeluaran($v['items'], $request->user()?->id);

        $msg = "Tersimpan: {$saved} baris Faktur Pajak Keluaran.";
        if ($skipped > 0) {
            $msg .= " {$skipped} entri UTJ & DP dilewati (belum dikonversi ke Uang Muka Penjualan).";
        }

        return back()->with($skipped > 0 ? 'error' : 'success', $msg);
    }

    /**
     * @param  array<int,array<string,mixed>>  $items
     * @return array{0:int,1:int}  [tersimpan, dilewati]
     */
    private function simpanMassalKeluaran(array $items, ?int $userId): array
    {
        $saved = 0;
        $skipped = 0;

        DB::transaction(function () use ($items, $userId, &$saved, &$skipped): void {
            foreach ($items as $item) {
                $no = ($item['tax_invoice_no'] ?? null) ?: null;
                $npwp = array_key_exists('npwp', $item) ? (($item['npwp'] ?? null) ?: null) : null;

                if ($item['row_type'] === 'jurnal') {
                    if ($no === null && $npwp === null) {
                        continue;
                    }
                    $this->simpanEntriBaris(BsFakturPajakLineEntry::KELUARAN, (int) $item['id'], $no, $npwp, $userId);
                    $saved++;

                    continue;
                }

                $entry = BsFakturKeluaranEntry::find($item['id']);
                if (! $entry) {
                    continue;
                }
                if (! $entry->is_active) {
                    $skipped++; // UTJ & DP yang belum dikonversi tidak bisa diisi

                    continue;
                }
                $entry->update(['fp_number' => $no]);
                $saved++;
            }
        });

        return [$saved, $skipped];
    }

    /**
     * Konversi UTJ/DP → Uang Muka Penjualan. Logikanya dipindah ke ConvertUtjDpToUangMuka
     * agar IDENTIK dengan tombol "Setujui & Posting" di Cash Book → Approval UM Penjualan;
     * gerbang idempoten di action mencegah entri yang sama dijurnal dua kali dari dua pintu.
     */
    public function konversiUangMuka(Request $request, BsFakturKeluaranEntry $entry, ConvertUtjDpToUangMuka $action): RedirectResponse
    {
        abort_unless($request->user()?->can('tax.nsfp'), 403);

        try {
            $journal = $action->execute($entry, (int) $request->user()->id);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "UTJ & DP dikonversi ke Uang Muka Penjualan — jurnal {$journal->journal_number} terposting. Nomor Faktur Pajak kini bisa diisi.");
    }

    // ─────────────────────────── Impor ───────────────────────────

    /**
     * Unduh FORMAT IMPOR FP & NPWP Faktur Pajak Keluaran — sudah terisi baris masa pajak ini.
     *
     * Sengaja bukan berkas kosong berisi judul kolom saja: baris-barisnya adalah baris jurnal,
     * dan yang mengikat baris di berkas dengan baris di sistem adalah JENIS_BARIS + ID_BARIS.
     * Kalau orang harus mengarang sendiri pasangan itu, impornya tidak akan pernah cocok. Jadi
     * yang diunduh adalah daftarnya apa adanya dengan dua kolom terakhir siap diisi.
     */
    public function formatImporKeluaran(Request $request): StreamedResponse
    {
        abort_unless($request->user()?->can('tax.nsfp'), 403);
        [$year, $month] = $this->period($request);

        $rows = $this->keluaranRows($year, $month)->map(fn (array $r) => [
            $r['row_type'], $r['id'], $r['doc_no'], $r['invoice_date'], $r['partner'],
            (float) $r['dpp'], (float) $r['ppn'], $r['tax_invoice_no'] ?? '', $r['npwp'] ?? '',
        ]);

        return $this->csv("format-impor-faktur-keluaran-{$year}-{$month}.csv", self::KOLOM_IMPOR, $rows);
    }

    /**
     * Impor FP & NPWP Faktur Pajak Keluaran dari berkas format di atas.
     *
     * Pemisahnya dideteksi dari baris judul — Excel berlokal Indonesia menyimpan CSV dengan
     * titik-koma, dan berkas semacam itu kalau dibaca dengan koma akan terbaca sebagai satu
     * kolom raksasa lalu gagal diam-diam tanpa satu baris pun tersimpan.
     */
    public function imporKeluaran(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('tax.nsfp'), 403);
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:4096']]);

        $isi = file_get_contents($request->file('file')->getRealPath());
        if ($isi === false || trim($isi) === '') {
            return back()->with('error', 'Berkas impor kosong.');
        }

        // Buang BOM UTF-8 dari Excel — tanpa ini sel pertama berisi tiga bita tak kasatmata di
        // depan "jurnal", dan baris pertama berkas tanpa judul kolom akan tertolak.
        $isi = (string) preg_replace('/^\xEF\xBB\xBF/', '', $isi);
        $baris = preg_split('/\r\n|\r|\n/', trim($isi)) ?: [];
        $sep = substr_count($baris[0] ?? '', ';') > substr_count($baris[0] ?? '', ',') ? ';' : ',';

        /* Baris pertama dibuang HANYA bila ia memang judul kolom. Orang lazim menghapus baris
           judul sebelum mengunggah; kalau baris pertama dibuang membabi buta, satu barisnya
           hilang diam-diam dan tidak ada yang memberi tahu. */
        if (! in_array(strtolower(trim((string) (str_getcsv($baris[0] ?? '', $sep, '"', '\\')[0] ?? ''))), ['jurnal', 'entry'], true)) {
            array_shift($baris);
        }

        $items = [];
        $ditolak = 0;
        foreach ($baris as $b) {
            if (trim($b) === '') {
                continue;
            }
            // Escape ditulis eksplisit: PHP 8.4 memperingatkan defaultnya akan berubah, dan
            // pembacaan harus tetap sepasang dengan penulisan di csv() di bawah.
            $c = str_getcsv($b, $sep, '"', '\\');
            $rowType = strtolower(trim((string) ($c[0] ?? '')));
            $id = (int) trim((string) ($c[1] ?? ''));
            if (! in_array($rowType, ['jurnal', 'entry'], true) || $id <= 0) {
                $ditolak++;

                continue;
            }
            $items[] = [
                'row_type'       => $rowType,
                'id'             => $id,
                'tax_invoice_no' => trim((string) ($c[7] ?? '')) ?: null,
                'npwp'           => trim((string) ($c[8] ?? '')) ?: null,
            ];
        }

        if ($items === []) {
            return back()->with('error', 'Tidak ada baris yang bisa dibaca dari berkas. Pakai berkas dari tombol "Format Impor" — kolom JENIS_BARIS & ID_BARIS harus ikut.');
        }

        [$saved, $skipped] = $this->simpanMassalKeluaran($items, $request->user()?->id);

        $msg = "Impor selesai: {$saved} baris tersimpan.";
        if ($skipped > 0) {
            $msg .= " {$skipped} entri UTJ & DP dilewati (belum dikonversi).";
        }
        if ($ditolak > 0) {
            $msg .= " {$ditolak} baris diabaikan (JENIS_BARIS/ID_BARIS tidak terbaca).";
        }

        return back()->with($skipped > 0 || $ditolak > 0 ? 'error' : 'success', $msg);
    }

    // ─────────────────────────── Ekspor ───────────────────────────

    /**
     * Ekspor Faktur Pajak Keluaran ke Excel.
     *
     * Bentuknya HTML bertipe vnd.ms-excel: layar ini menggabungkan baris jurnal dengan entri
     * approval yang belum berjurnal, jadi tidak ada satu query Eloquent yang bisa mewakilinya.
     *
     * Saringan kolom di layar dihitung di sisi peramban dan tidak pernah masuk URL, jadi berkas
     * ini berisi SELURUH masa pajak yang dipilih. Itu dikatakan di judul lembarnya, bukan didiamkan.
     */
    public function exportKeluaranXlsx(Request $request): \Illuminate\Http\Response
    {
        abort_unless($request->user()?->can('tax.export'), 403);
        [$year, $month] = $this->period($request);

        $rows = $this->keluaranRows($year, $month);

        $tabel = $this->xlsTabel(
            ['No. Faktur Pajak', 'Sumber', 'Tanggal', 'NPWP', 'Nama', 'No. Dokumen / Unit', 'DPP', 'PPN', 'Keterangan', 'Status Konversi'],
            $rows->map(fn (array $r) => [
                'teks' => [$r['tax_invoice_no'] ?? '', self::SUMBER[$r['source']] ?? $r['source'], $r['invoice_date'],
                    $r['npwp'] ?? '', $r['partner'], $r['doc_no']],
                'angka' => [$r['dpp'], $r['ppn']],
                // Status Konversi hanya bermakna bagi entri approval yang belum berjurnal; baris
                // jurnal sudah ada di buku besar, jadi tidak ada yang perlu dikonversi lagi.
                'ekor' => [$r['keterangan'] ?? '',
                    $r['row_type'] === 'entry'
                        ? ($r['needs_konversi'] ? 'Belum dikonversi ke Uang Muka' : 'Aktif')
                        : 'Sudah berjurnal'],
            ]),
            (float) $rows->sum('dpp'), (float) $rows->sum('ppn'), 6, '#16a34a'
        );

        $file = 'Faktur-Pajak-Keluaran-'.sprintf('%04d-%02d', $year, $month).'-'.now()->format('Ymd-His').'.xls';

        return $this->xlsResponse('FAKTUR PAJAK KELUARAN', $year, $month, $tabel, $file);
    }

    /** Ekspor Faktur Pajak Masukan ke Excel — daftar yang SAMA dengan layarnya. */
    public function exportMasukanXlsx(Request $request): \Illuminate\Http\Response
    {
        abort_unless($request->user()?->can('tax.export'), 403);
        [$year, $month] = $this->period($request);

        $rows = $this->masukanRows($year, $month);

        $tabel = $this->xlsTabel(
            ['No. Faktur Pajak', 'Tanggal', 'NPWP', 'Nama Supplier', 'No. Inv. Supplier', 'No. Jurnal', 'DPP', 'PPN', 'Keterangan'],
            $rows->map(fn (array $r) => [
                'teks' => [$r['tax_invoice_no'] ?? '', $r['invoice_date'], $r['npwp'] ?? '', $r['partner'],
                    $r['supplier_invoice_no'] ?? '', $r['invoice_number']],
                'angka' => [$r['dpp'], $r['ppn']],
                'ekor' => [$r['keterangan'] ?? ''],
            ]),
            (float) $rows->sum('dpp'), (float) $rows->sum('ppn'), 6, '#dc2626'
        );

        $file = 'Faktur-Pajak-Masukan-'.sprintf('%04d-%02d', $year, $month).'-'.now()->format('Ymd-His').'.xls';

        return $this->xlsResponse('FAKTUR PAJAK MASUKAN', $year, $month, $tabel, $file);
    }

    /**
     * Rakit tabel HTML-Excel: kolom identitas dipaksa dibaca sebagai TEKS.
     *
     * Tanpa mso-number-format teks, Nomor Seri Faktur Pajak dan NPWP yang panjang berubah jadi
     * notasi ilmiah dan nol depannya hilang — persis kolom yang paling tidak boleh berubah.
     *
     * @param  array<int,string>  $header
     * @param  Collection<int,array{teks:array<int,mixed>,angka:array<int,mixed>,ekor:array<int,mixed>}>  $rows
     */
    private function xlsTabel(array $header, Collection $rows, float $totalDpp, float $totalPpn, int $kolomTotal, string $warna): string
    {
        $e = fn ($v) => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES);
        $t = fn ($v) => '<td style="mso-number-format:\'\@\'">'.$e($v).'</td>';
        $n = fn ($v) => '<td>'.number_format((float) ($v ?? 0), 2, ',', '.').'</td>';

        $h = '<table border="1"><tr style="background:'.$warna.';color:#fff">';
        foreach ($header as $kol) {
            $h .= '<th>'.$e($kol).'</th>';
        }
        $h .= '</tr>';

        foreach ($rows as $r) {
            $h .= '<tr>';
            foreach ($r['teks'] as $v) {
                $h .= $t($v);
            }
            foreach ($r['angka'] as $v) {
                $h .= $n($v);
            }
            foreach ($r['ekor'] as $v) {
                $h .= $t($v);
            }
            $h .= '</tr>';
        }

        // Sel kosong penutup dihitung dari jumlah judulnya, bukan dipatok satu: menambah satu
        // kolom keterangan tanpa menyesuaikannya membuat baris Total lebih pendek dari tabelnya
        // dan angkanya bergeser — tanpa galat apa pun.
        $penutup = max(0, count($header) - $kolomTotal - 2);

        return $h.'<tr style="font-weight:bold;background:#f1f5f9"><td colspan="'.$kolomTotal.'">Total ('.$rows->count().' baris)</td>'
            .$n($totalDpp).$n($totalPpn).str_repeat('<td></td>', $penutup).'</tr></table>';
    }

    private function xlsResponse(string $judul, int $year, int $month, string $tabel, string $file): \Illuminate\Http\Response
    {
        return response('<html xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="utf-8"></head><body>'
            .'<h3>'.$judul.'</h3>'
            .'<p>Masa pajak: '.sprintf('%02d/%04d', $month, $year).' — seluruh baris masa ini (saringan kolom di layar tidak diterapkan).</p>'
            .$tabel.'</body></html>')
            ->header('Content-Type', 'application/vnd.ms-excel; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="'.$file.'"');
    }

    public function exportKeluaran(Request $request): StreamedResponse
    {
        abort_unless($request->user()?->can('tax.export'), 403);
        [$year, $month] = $this->period($request);

        // Membaca daftar YANG SAMA dengan layarnya. Dulu merakit ulang daftarnya sendiri dan
        // melewatkan sebagian sumber — berkasnya diam-diam kekurangan baris dibanding layarnya.
        $rows = $this->keluaranRows($year, $month)->map(fn (array $r) => [
            $r['tax_invoice_no'] ?? '', $r['invoice_date'], self::SUMBER[$r['source']] ?? $r['source'],
            $r['npwp'] ?? '', $r['partner'], $r['doc_no'], (float) $r['dpp'], (float) $r['ppn'],
        ]);

        return $this->csv("faktur-keluaran-{$year}-{$month}.csv",
            ['No Faktur Pajak', 'Tgl', 'Sumber', 'NPWP', 'Nama', 'No Dokumen', 'DPP', 'PPN'], $rows);
    }

    public function exportMasukan(Request $request): StreamedResponse
    {
        abort_unless($request->user()?->can('tax.export'), 403);
        [$year, $month] = $this->period($request);

        // Membaca daftar YANG SAMA dengan layarnya, sebab layarnya kini dibentuk dari mutasi akun
        // PPN Masukan — merakit ulang dari bs_ap_invoices menghasilkan berkas yang lebih pendek.
        $rows = $this->masukanRows($year, $month);

        return $this->csv("faktur-masukan-{$year}-{$month}.csv",
            ['No Faktur Pajak', 'Tgl', 'NPWP', 'Nama', 'No Invoice Supplier', 'No Jurnal', 'DPP', 'PPN'],
            $rows->map(fn (array $r) => [
                $r['tax_invoice_no'] ?? '', $r['invoice_date'], $r['npwp'] ?? '',
                $r['partner'], $r['supplier_invoice_no'] ?? '', $r['invoice_number'],
                (float) $r['dpp'], (float) $r['ppn'],
            ]));
    }

    /** @return array{0:int,1:int} */
    private function period(Request $request): array
    {
        $year  = (int) ($request->integer('year') ?: now()->year);
        $month = (int) ($request->integer('month') ?: now()->month);

        return [$year, max(1, min(12, $month))];
    }

    private function accPeriodMutation(string $code, int $year, int $month, bool $debitNormal = false): float
    {
        $accId = BsAccount::where('code', $code)->value('id');
        if (! $accId) {
            return 0.0;
        }
        $r = DB::table('bs_journal_lines')->join('bs_journals', 'bs_journals.id', '=', 'bs_journal_lines.journal_id')
            ->where('bs_journals.status', 'posted')->where('bs_journal_lines.account_id', $accId)
            ->whereYear('bs_journals.journal_date', $year)->whereMonth('bs_journals.journal_date', $month)
            ->selectRaw('COALESCE(SUM(debit),0) d, COALESCE(SUM(credit),0) c')->first();

        return $debitNormal ? round((float) $r->d - (float) $r->c, 2) : round((float) $r->c - (float) $r->d, 2);
    }

    /** @param \Illuminate\Support\Collection<int,array<int,mixed>> $rows */
    private function csv(string $filename, array $header, $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $rows): void {
            $out = fopen('php://output', 'wb');
            fputcsv($out, $header, ',', '"', '\\');
            foreach ($rows as $row) {
                fputcsv($out, $row, ',', '"', '\\');
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
