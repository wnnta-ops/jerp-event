<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Tax;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsApInvoice;
use App\Domain\BusinessSupport\Models\BsArInvoice;
use App\Domain\BusinessSupport\Models\BsJournal;
use App\Domain\BusinessSupport\Services\BukuBesarDokumen;
use App\Domain\BusinessSupport\Services\JournalPartyResolver;
use App\Domain\BusinessSupport\Support\PosisiHutang;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Rekap PPh (withholding) untuk SPT Masa PPh — pemotongan yang menjadi kewajiban perusahaan.
 *   PPh 21      (gaji)        → akun 20104040402, sumber Payroll + AP
 *   PPh 23      (jasa)        → akun 20104040403, sumber AP
 *   PPh 4 ayat 2 (final)      → akun 20104040401, sumber AP
 *   PPh 25/29                 → akun 20104040404, sumber AP
 *   PPh Dibayar Dimuka (kredit pajak, dipungut pelanggan) → 1010502xx, sumber AR (informasi)
 */
class PphController extends Controller
{
    public function __construct(
        private readonly BukuBesarDokumen $bb,
        private readonly JournalPartyResolver $party,
    ) {}

    private const ARTICLES = [
        '20104040402' => 'PPh Pasal 21',
        '20104040403' => 'PPh Pasal 23',
        '20104040401' => 'PPh Pasal 4(2)',
        '20104040404' => 'PPh Pasal 25/29',
    ];

    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('tax.pph.view'), 403);
        [$year, $month] = $this->period($request);

        $d = $this->hitung($year, $month, PosisiHutang::dariRequest($request));

        return Inertia::render('BusinessSupport/Tax/Pph', $d);
    }

    /**
     * Rekap PPh dipotong — DIGERAKKAN BUKU BESAR, satu sumber untuk layar & ekspor.
     *
     * KENAPA DARI BUKU BESAR: versi sebelumnya membaca kolom bs_ap_invoices.pph_amount dan
     * bs_payroll_run_lines.pph21. Itu membuat sebagian pemotongan MUSTAHIL muncul — jalur
     * Posting Invoice (komisi/GMC, PO, SPK) memaku pph_amount = 0 pada setiap invoice yang
     * dibuatnya (PostingInvoiceController::makeInvoice), padahal jurnalnya tetap mengkredit
     * akun Hutang PPh. Akibatnya kartu PPh 23 bisa berbunyi "Rp 0" sementara barisnya jelas
     * ada di Detail Transaksi Jurnal, dan tabelnya kosong tanpa satu pun galat.
     *
     * Akun Hutang PPh adalah CATATAN RESMI kewajiban setor. Membacanya langsung membuat rekap
     * ini sama dengan Detail Transaksi Jurnal untuk keempat akun itu — bukan mendekati, tetapi
     * sama karena dihitung dari angka yang sama.
     *
     * @return array<string,mixed>
     */
    private function hitung(int $year, int $month, string $posisi): array
    {
        $akun     = BsAccount::whereIn('code', array_keys(self::ARTICLES))->get(['id', 'code', 'name']);
        $codeById = $akun->pluck('code', 'id');
        $idByCode = $akun->pluck('id', 'code');

        $dari = sprintf('%04d-%02d-01', $year, $month);
        $sampai = date('Y-m-t', strtotime($dari));

        $lines = $this->bb->lines($akun->pluck('id')->map(fn ($v) => (int) $v)->all(), $dari, $sampai);

        $journalIds = $lines->pluck('journal_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
        $dokumen    = $this->bb->documentsFor($journalIds);
        // Nama pihak memakai resolver yang sudah dipakai Detail Transaksi Jurnal — supaya
        // "Lawan Transaksi" di sini sama dengan "Pihak Terkait" di sana untuk jurnal yang sama.
        $pihak = $this->party->forJournalIds($journalIds);

        // DPP hanya tersedia bila dokumennya invoice hutang; untuk jalur lain memang tidak ada
        // angka DPP tersimpan, dan mengarangnya (mis. membalik dari tarif) akan menghasilkan
        // angka yang tidak ada di dokumen mana pun.
        $apIds = collect($dokumen)->filter(fn ($x) => $x['type'] === 'ap_invoice')->pluck('id')->unique()->values()->all();
        $dpp   = $apIds === [] ? collect() : BsApInvoice::whereIn('id', $apIds)->pluck('dpp', 'id');

        // ── Penyaring posisi hutang ──
        // Layar ini digerakkan BUKU BESAR, bukan tabel invoice: sebagian barisnya (PPh 21
        // dari Payroll, jurnal manual) tidak punya invoice hutang sama sekali, jadi tidak
        // punya posisi apa pun. Barisan semacam itu DISISIHKAN saat sebuah posisi dipilih —
        // memasukkannya ke "Hutang Usaha" berarti melaporkan potongan gaji sebagai hutang
        // usaha — dan jumlahnya DILAPORKAN ke layar, bukan hilang diam-diam.
        $disisihkan = 0;
        if ($posisi !== PosisiHutang::SEMUA) {
            $sah = $apIds === []
                ? collect()
                : PosisiHutang::saring(BsApInvoice::whereIn('id', $apIds), $posisi)->pluck('id')->flip();

            $sebelum = $lines->count();
            $lines = $lines->filter(function ($l) use ($dokumen, $sah) {
                $doc = $dokumen[(int) $l->journal_id] ?? null;

                return $doc !== null && $doc['type'] === 'ap_invoice' && isset($sah[$doc['id']]);
            })->values();
            $disisihkan = $sebelum - $lines->count();
        }

        $rows = $lines->map(function ($l) use ($codeById, $dokumen, $pihak, $dpp) {
            $j    = $l->journal;
            $doc  = $dokumen[(int) $l->journal_id] ?? null;
            $kode = (string) ($codeById[$l->account_id] ?? '');
            $nilai = round((float) $l->credit - (float) $l->debit, 2);

            return [
                'source'         => $doc['label'] ?? (BsJournal::SOURCES[$j?->source] ?? 'Jurnal'),
                'date'           => optional($j?->journal_date)->format('Y-m-d'),
                'partner'        => $pihak[(int) $l->journal_id] ?? '—',
                'doc_no'         => $doc['number'] ?? ($j?->reference ?: $j?->journal_number),
                'article'        => self::ARTICLES[$kode] ?? $kode,
                'account_code'   => $kode,
                'base'           => ($doc && $doc['type'] === 'ap_invoice') ? (float) ($dpp[$doc['id']] ?? 0) : null,
                'pph'            => $nilai,
                'journal_id'     => (int) $l->journal_id,
                'journal_number' => $j?->journal_number,
                'status'         => (string) ($j?->status ?? ''),
                'status_label'   => BsJournal::STATUSES[$j?->status] ?? (string) ($j?->status ?? '—'),
                // Hanya jurnal Approved yang efektif jadi kewajiban setor; yang belum tetap
                // DITAMPILKAN supaya rinciannya sama dengan Detail Transaksi Jurnal, tetapi
                // tidak ikut menaikkan angka SPT.
                'efektif'        => ($j?->status ?? '') === 'posted',
            ];
        })->sortBy('date')->values();

        $byArticle = [];
        $belum     = [];
        foreach (self::ARTICLES as $label) {
            $sepasal = $rows->where('article', $label);
            $byArticle[$label] = round((float) $sepasal->where('efektif', true)->sum('pph'), 2);
            $belum[$label]     = round((float) $sepasal->where('efektif', false)->sum('pph'), 2);
        }

        // PPh Dibayar Dimuka (kredit pajak) dari AR — dipungut pelanggan.
        $prepaid = (float) BsArInvoice::where('pph_amount', '>', 0)
            ->whereYear('invoice_date', $year)->whereMonth('invoice_date', $month)->sum('pph_amount');

        // Cross-check GL tetap dihitung TERPISAH lewat query sendiri, bukan diambil dari
        // $byArticle. Kalau disamakan begitu saja, kartunya akan selalu bercentang hijau —
        // termasuk ketika perhitungan di atas rusak, dan justru itu yang perlu ketahuan.
        $gl = [];
        foreach (self::ARTICLES as $code => $label) {
            $gl[$label] = $this->accCreditMutation($code, $year, $month);
        }

        return [
            'period'        => ['year' => $year, 'month' => $month],
            'rows'          => $rows,
            'byArticle'     => $byArticle,
            'belumApproved' => $belum,
            'totalTerutang' => round(array_sum($byArticle), 2),
            'prepaid'       => round($prepaid, 2),
            'gl'            => $gl,
            'posisi'        => $posisi,
            'posisiOpsi'    => PosisiHutang::opsi(),
            // Berapa baris buku besar yang tidak bisa ditelusuri ke invoice hutang, sehingga
            // tidak punya posisi. Ditampilkan layar sebagai keterangan — operator harus tahu
            // angka rekapnya sedang tidak memuat seluruh pemotongan masa itu.
            'disisihkan'    => $disisihkan,
            // Kartu cross-check GL SENGAJA tetap dihitung dari SELURUH posisi lewat query
            // sendiri (accCreditMutation). Menyaringnya juga akan membuat centangnya selalu
            // hijau; membiarkannya dibandingkan dengan tabel yang tersaring akan membuatnya
            // selalu merah. Keduanya salah — jadi layar diberi tahu bahwa kartu itu memang
            // berbicara tentang seluruh posisi, dan tidak boleh dibaca sebagai selisih.
            'glSeluruhPosisi' => $posisi !== PosisiHutang::SEMUA,
            // Dipakai layar untuk menaut ke Detail Transaksi Jurnal per akun, membawa rentang
            // tanggal masa pajaknya — layar tujuan berbawaan "bulan berjalan", jadi tanpa
            // rentang ini tautannya mendarat di masa yang salah.
            'akun'          => $akun->map(fn ($a) => [
                'id' => (int) $a->id, 'code' => $a->code, 'name' => $a->name,
                'article' => self::ARTICLES[$a->code] ?? $a->code,
            ])->values(),
            'periode'       => ['from' => $dari, 'to' => $sampai],
        ];
    }

    /**
     * Ekspor CSV — memakai hitung() yang SAMA dengan layarnya.
     *
     * Sebelumnya ekspor ini punya query sendiri yang menyalin logika layar. Salinan itu ikut
     * mewarisi cacatnya (pemotongan dari jalur Posting Invoice tidak pernah terbaca) DAN bisa
     * menyimpang sendiri saat salah satunya disesuaikan — tanpa satu pun galat, karena berkas
     * dan layar sama-sama masuk akal dilihat sendiri-sendiri.
     */
    public function export(Request $request): StreamedResponse
    {
        abort_unless($request->user()?->can('tax.pph.export'), 403);
        [$year, $month] = $this->period($request);

        $rows = collect($this->hitung($year, $month, PosisiHutang::dariRequest($request))['rows']);

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'wb');
            fputcsv($out, ['Sumber', 'Tgl', 'Lawan Transaksi', 'No Dokumen', 'Pasal', 'Akun', 'No Jurnal', 'Status', 'DPP/Bruto', 'PPh']);
            foreach ($rows as $r) {
                fputcsv($out, [
                    $r['source'], $r['date'], $r['partner'], $r['doc_no'], $r['article'],
                    $r['account_code'], $r['journal_number'], $r['status_label'],
                    // DPP dikosongkan apa adanya bila dokumennya bukan invoice hutang —
                    // menuliskan 0 akan terbaca sebagai "DPP-nya nol", bukan "tidak tersedia".
                    $r['base'] === null ? '' : $r['base'],
                    $r['pph'],
                ]);
            }
            fclose($out);
        }, "rekap-pph-{$year}-{$month}.csv", ['Content-Type' => 'text/csv']);
    }

    /** @return array{0:int,1:int} */
    private function period(Request $request): array
    {
        $year  = (int) ($request->integer('year') ?: now()->year);
        $month = (int) ($request->integer('month') ?: now()->month);

        return [$year, max(1, min(12, $month))];
    }

    private function accCreditMutation(int|string $code, int $year, int $month): float
    {
        $id = BsAccount::where('code', (string) $code)->value('id');
        if (! $id) {
            return 0.0;
        }
        $r = DB::table('bs_journal_lines')->join('bs_journals', 'bs_journals.id', '=', 'bs_journal_lines.journal_id')
            ->where('bs_journals.status', 'posted')->where('bs_journal_lines.account_id', $id)
            // Jurnal yang sudah dihapus TIDAK ikut. Query ini memakai DB::table, jadi global
            // scope soft delete tidak berlaku — tanpa baris ini, jurnal terhapus tetap
            // dihitung di sini padahal rincian rekapnya (lewat Eloquent) membuangnya, dan
            // centang hijau pada kartunya hilang tanpa sebab yang bisa dijelaskan operator.
            ->whereNull('bs_journals.deleted_at')
            // Alasan yang sama untuk TENANT. Rinciannya dibaca lewat Eloquent (BsJournal
            // memakai HasTenant), jadi hanya jurnal tenant berjalan yang masuk daftar;
            // tanpa saringan ini angka GL menjumlahkan jurnal SEMUA tenant. Kartunya lalu
            // memperlihatkan selisih yang tidak bisa ditelusuri ke satu baris pun di
            // tabelnya — persis bentuk kesalahan yang paling sulit dijelaskan.
            ->where('bs_journals.tenant_id', tenant_id())
            ->whereYear('bs_journals.journal_date', $year)->whereMonth('bs_journals.journal_date', $month)
            ->selectRaw('COALESCE(SUM(credit),0) c, COALESCE(SUM(debit),0) d')->first();

        return round((float) $r->c - (float) $r->d, 2);
    }
}
