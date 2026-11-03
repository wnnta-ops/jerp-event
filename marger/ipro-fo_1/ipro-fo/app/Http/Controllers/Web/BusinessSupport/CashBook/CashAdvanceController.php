<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\CashBook;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsCashAdvance;
use App\Domain\BusinessSupport\Models\BsCashAdvanceAllocation;
use App\Domain\BusinessSupport\Models\BsCostCenter;
use App\Domain\BusinessSupport\Models\BsEmployee;
use App\Domain\BusinessSupport\Models\BsJournal;
use App\Domain\BusinessSupport\Print\PengajuanKasbonPrinter;
use App\Domain\BusinessSupport\Services\CashAdvanceAllocationService;
use App\Domain\BusinessSupport\Services\CashAdvanceService;
use App\Domain\BusinessSupport\Services\CostCenterAkun;
use App\Domain\BusinessSupport\Services\CostCenterPengguna;
use App\Domain\BusinessSupport\Support\SaldoBonSementara;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Concerns\GalatAnggaran;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class CashAdvanceController extends Controller
{
    use GalatAnggaran;

    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('cashbook.advance.view'), 403);

        $rows = $this->batasiKeMilikSendiri(BsCashAdvance::query(), $request)
            ->with('cashAccount:id,code,name')
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            // Bon BATAL punya layarnya sendiri (Transaksi Bon Batal). Dikeluarkan dari sini
            // supaya daftar kerja tidak bercampur dengan pengajuan yang sudah ditolak — masih
            // bisa dilihat di sini dengan memilih status "Batal".
            ->when(! $request->input('status'), fn ($q) => $q->whereNull('cancelled_at'))
            // #87: filter per pemegang/department (holder = "Nama — Departemen") / keperluan / no.
            ->when($request->input('search'), fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('holder', 'like', "%{$s}%")
                ->orWhere('purpose', 'like', "%{$s}%")
                ->orWhere('advance_number', 'like', "%{$s}%")))
            ->latest('advance_date')->latest('id')
            ->paginate(50)->withQueryString();

        return Inertia::render('BusinessSupport/CashBook/Advances/Index', [
            'advances' => $rows,
            'filters' => $request->only(['status', 'search']),
            'summary' => [
                // Ringkasannya ikut dibatasi: angka "Panjar Outstanding" seluruh perusahaan
                // di atas daftar yang hanya berisi bon sendiri membuat orang mengira bonnya
                // sendirilah yang sebesar itu.
                'outstanding' => (float) $this->batasiKeMilikSendiri(BsCashAdvance::query(), $request)
                    ->where('status', 'outstanding')->sum('amount'),
                'count' => $this->batasiKeMilikSendiri(BsCashAdvance::query(), $request)
                    ->where('status', 'outstanding')->count(),
            ],
            'buckets' => $this->indexBuckets($request),                                                  // #134
            // Tombol "Laporan" dulu terbit untuk semua orang padahal report() menuntut izinnya
            // sendiri — yang tidak memegangnya menekan tombol itu lalu mendapat 403. Laporannya
            // juga memuat bon SELURUH karyawan, jadi membiarkannya terbuka di layar yang kini
            // hanya berisi bon sendiri justru membatalkan pembatasannya.
            'canReport' => (bool) $request->user()?->can('cashbook.advance.report'),
            // IZINNYA DISAMAKAN DENGAN AKSINYA: setReceivedDate() menuntut
            // cashbook.advance.received-date, sedangkan isian ini dulu muncul untuk siapa pun
            // yang boleh MELIHAT bon — jadi kebanyakan orang disodori isian yang berakhir 403
            // begitu tanggalnya diubah.
            'canEditDocDate' => (bool) $request->user()?->can('cashbook.advance.received-date'), // #125
            // REALISASI milik PENGAJU; REFUND & REIMBURSE milik DEPT FINANCE (12 Okt 2026).
            // Realisasi digerbangi IZIN saja, sebab keduanya boleh melakukannya: pengaju yang
            // biasa, Finance sebagai pembetul bila pengaju salah isi. Refund/Reimburse tidak —
            // keduanya memindahkan uang masuk/keluar bank, dan yang memegang kas adalah
            // Finance; panelnya dipagari modeFinance di layar (izin saja tak memisahkan,
            // karena SuperAdmin memegang semuanya).
            'canRealize' => (bool) $request->user()?->can('cashbook.advance.realize'),
            'canRefund' => (bool) $request->user()?->can('cashbook.advance.refund'),
            'canReimburse' => (bool) $request->user()?->can('cashbook.advance.reimburse'),
            // Dibuka dari menu Finance (?finance=1) → barisnya menuju tampilan Finance.
            // Dari menu pengaju → menuju tampilan baca-saja. Satu daftar, dua pintu keluar.
            'modeFinance' => $request->boolean('finance')
                && (bool) $request->user()?->can('cashbook.advance.pay'),
        ]);
    }

    /**
     * #134 — 3 bucket realisasi untuk tab di halaman bon-sementara.
     * Memakai logika settlement yang sama dengan reportBuckets():
     *   settlement = amount − realized_amount
     *     = 0 → realisasi sama dengan pengajuan (Realisasi)
     *     > 0 → realisasi lebih kecil (ada sisa → Refund)
     *     < 0 → realisasi lebih besar (kurang → Reimburse)
     */
    /**
     * DAFTAR BON HANYA MENAMPILKAN MILIK SENDIRI — permintaan Dept Finance, 12 Okt 2026.
     * DIPERLUAS 19 Okt 2026: + bon satu cost center (tim) — anggota tim ikut tahu & boleh
     * menindaklanjuti pengajuan rekannya. Aturannya kini di AksesBon::batasi().
     *
     * Sebelumnya setiap orang yang boleh MELIHAT bon melihat bon seluruh karyawan: nominal,
     * keperluan, dan siapa memegang berapa. Itu bukan sekadar daftar yang panjang — nilai
     * panjar orang lain memang bukan urusan pengaju.
     *
     * KEPEMILIKAN DICOCOKKAN DUA JALUR, karena satu saja tidak cukup:
     *
     *   created_by  jalur utama — id akun yang mengajukan.
     *   holder      cadangan untuk bon lama yang created_by-nya kosong (hasil impor/seeder).
     *               Dicocokkan PERSIS atau "Nama — Jabatan", bukan LIKE berawalan: dengan
     *               awalan, "Aji" akan ikut menarik bon "Aji Trimantanto" — orang lain.
     *
     * Yang TETAP melihat semuanya:
     *   cashbook.advance.pay        Dept Finance — merekalah yang membayar & menyelesaikan.
     *   businesssupport.approval.act Penyetuju — bon menunggu tanda tangannya, dan satu-satunya
     *                               pintu menuju bon itu adalah daftar ini. Tanpa pengecualian
     *                               ini, persetujuan berjenjang mati tanpa galat apa pun:
     *                               penyetuju membuka daftar, melihat kosong, dan bonnya
     *                               menggantung selamanya.
     */
    private function bolehLihatSemuaBon(Request $request): bool
    {
        // Aturannya di satu tempat — dipakai juga Outstanding Kasbon.
        return \App\Domain\BusinessSupport\Support\AksesBon::lihatSemua($request->user());
    }

    /**
     * Bolehkah pengguna ini MEMBUKA satu bon tertentu?
     *
     * Memakai aturan yang sama dengan daftar. Tanpa ini pembatasan daftar hanya kosmetik:
     * nomor bon berurutan, jadi mengganti angka di URL cukup untuk membaca panjar orang lain —
     * nominal, keperluan, dan rincian realisasinya.
     */
    private function bolehBukaBon(Request $request, BsCashAdvance $advance): bool
    {
        return \App\Domain\BusinessSupport\Support\AksesBon::bolehBuka($advance, $request->user());
    }

    /** Terapkan pembatasan di atas pada sebuah query bon. Dipakai daftar, ringkasan, & tab. */
    private function batasiKeMilikSendiri(Builder $q, Request $request): Builder
    {
        // Milik sendiri + satu cost center (tim) — aturannya di AksesBon.
        return \App\Domain\BusinessSupport\Support\AksesBon::batasi($q, $request->user());
    }

    private function indexBuckets(Request $request): array
    {
        $map = fn ($a) => [
            'id' => $a->id,
            'advance_number' => $a->advance_number,
            'advance_date' => $a->advance_date?->toDateString(),
            'realized_date' => $a->realized_date?->toDateString(),
            'received_document_date' => $a->received_document_date?->toDateString(),
            // Peristiwa KEDUA — berkas realisasi. Ikut dibawa karena daftar menampilkan
            // keduanya berdampingan; tanpa ini kolomnya selalu kosong di layar.
            'realisasi_document_date' => $a->realisasi_document_date?->toDateString(),
            'holder' => $a->holder,
            'purpose' => $a->purpose,
            'amount' => (float) $a->amount,
            'realized_amount' => (float) $a->realized_amount,
            'settlement' => (float) $a->settlement,
            'status' => $a->status,
            // Kolom Kategori: cara pembayarannya. paid_date ikut dibawa karena itulah penanda
            // bon sudah BENAR-BENAR dibayar — payment_method berbawaan 'reguler' sejak bonnya
            // dibuat, jadi memakainya sendirian akan melabeli bon yang belum dibayar sebagai
            // "Pembayaran Reguler".
            'payment_method' => $a->payment_method,
            'paid_date' => $a->paid_date?->toDateString(),
        ];

        $realisasi = $this->batasiKeMilikSendiri(BsCashAdvance::query(), $request)
            ->where('status', 'realized')->where('settlement', '=', 0)
            ->latest('realized_date')->latest('id')->get();
        $refund = $this->batasiKeMilikSendiri(BsCashAdvance::query(), $request)
            ->where('status', 'realized')->where('settlement', '>', 0)
            ->latest('realized_date')->latest('id')->get();
        $reimburse = $this->batasiKeMilikSendiri(BsCashAdvance::query(), $request)
            ->where('status', 'realized')->where('settlement', '<', 0)
            ->latest('realized_date')->latest('id')->get();

        $bucket = fn ($rows, string $totalMode = 'amount') => [
            'rows' => $rows->map($map)->values(),
            'count' => $rows->count(),
            'total' => $totalMode === 'amount'
                ? (float) $rows->sum('amount')
                : (float) $rows->sum(fn ($a) => abs((float) $a->settlement)),
        ];

        return [
            'realisasi' => $bucket($realisasi),
            'refund' => $bucket($refund, 'settlement'),
            'reimburse' => $bucket($reimburse, 'settlement'),
        ];
    }

    /** #125 — set Tanggal Terima Dokumen (hanya finance: businesssupport.transaction.create). */
    /**
     * Tanggal Finance menerima berkas REALISASI — terpisah dari berkas pengajuan.
     *
     * Izinnya sama dengan setReceivedDate(): keduanya perbuatan yang sama, yaitu Finance
     * mencatat penerimaan fisik berkas. Membuat izin kedua hanya menambah satu saklar lagi
     * yang harus diingat orang saat menyetel peran.
     */
    public function setRealisasiDate(Request $request, BsCashAdvance $advance): RedirectResponse
    {
        abort_unless($request->user()?->can('cashbook.advance.received-date'), 403);
        $v = $request->validate(['realisasi_document_date' => ['nullable', 'date']]);
        $advance->update(['realisasi_document_date' => $v['realisasi_document_date'] ?? null]);

        return back()->with('success', 'Tanggal terima berkas realisasi disimpan.');
    }

    public function setReceivedDate(Request $request, BsCashAdvance $advance): RedirectResponse
    {
        abort_unless($request->user()?->can('cashbook.advance.received-date'), 403);
        $v = $request->validate(['received_document_date' => ['nullable', 'date']]);
        $advance->update(['received_document_date' => $v['received_document_date'] ?? null]);

        return back()->with('success', 'Tanggal terima dokumen disimpan.');
    }

    /** #127 — persetujuan bon sementara (berjenjang). */
    public function approve(Request $request, BsCashAdvance $advance, CashAdvanceService $svc): RedirectResponse
    {
        abort_unless($request->user()?->canAny(['cashbook.advance.approve', 'businesssupport.approval.act']), 403);
        $v = $request->validate(['slot' => ['required', 'string', 'in:'.implode(',', array_keys(BsCashAdvance::SIGNERS))]]);
        try {
            $svc->approve($advance, $v['slot'], $request->user()->id, (string) $request->user()->name);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Persetujuan bon dicatat.');
    }

    /**
     * SALDO DEBIT CARD — ketiga angka Set Up Debit Card, tetapi dipecah PER NOMOR BON.
     *
     * Set Up Debit Card menjawab "berapa saldo kartu ini bulan ini"; layar ini menjawab
     * "bon mana yang menaruhnya di sana, dan bon mana yang memakainya". Keduanya membaca sumber
     * yang sama persis supaya angkanya tidak pernah berbeda:
     *
     *   Penambahan = nominal pembayaran Debit VA yang sudah tersimpan (amount, bertanggal paid_date)
     *   Pemakaian  = total Alokasi Kasbon bon itu
     *   Sisa Saldo = Penambahan − Pemakaian
     *
     * Hanya bon Debit VA yang SUDAH dibayar yang muncul: bon yang belum dibayar belum menaruh
     * sepeser pun di kartu, dan menampilkannya sebagai baris bersaldo 0 hanya membuat daftarnya
     * panjang tanpa menambah keterangan apa pun.
     *
     * Riwayat tiap bon = baris-baris Alokasi Kasbon-nya, yaitu satu-satunya cara saldo kartu
     * berkurang.
     */
    public function cardBalance(Request $request): Response
    {
        // Izinnya disamakan dengan Kartu Bon Sementara: layar ini menampilkan data
        // kartu yang sama, hanya sudut pandangnya per bon. canModule() yang dipakai
        // sebelumnya adalah cek tingkat-MODUL — siapa pun yang punya satu izin
        // cashbook apa pun lolos, termasuk yang tak berhak melihat kartu.
        abort_unless($request->user()?->can('cashbook.advance.card'), 403);

        $bons = BsCashAdvance::query()
            ->with('creditCard:id,card_number,name')
            ->where('payment_method', 'debit_va')
            ->whereNotNull('paid_date')
            ->orderByDesc('advance_date')->orderByDesc('id')
            ->get();

        /* Alokasi diambil SEKALIGUS lalu dikelompokkan di PHP. Mengambilnya per bon di dalam
           perulangan membuat satu kueri per baris — daftar 200 bon berarti 200 kueri, dan
           lambatnya baru terasa setelah datanya banyak. */
        $alokasi = BsCashAdvanceAllocation::query()
            ->with('cashAccount:id,code,name')
            ->whereIn('cash_advance_id', $bons->pluck('id')->all())
            ->orderBy('alloc_date')->orderBy('id')
            ->get()
            ->groupBy('cash_advance_id');

        $rows = $bons->map(function ($b) use ($alokasi) {
            $baris = $alokasi->get($b->id) ?? collect();
            $penambahan = round((float) $b->amount, 2);
            $pemakaian = round((float) $baris->sum('amount'), 2);

            return [
                'no' => $b->advance_number,
                'date' => optional($b->advance_date)->toDateString(),
                'holder' => $b->holder,
                'purpose' => $b->purpose,
                'card' => $b->creditCard?->label(),
                'penambahan' => $penambahan,
                'pemakaian' => $pemakaian,
                'sisa' => round($penambahan - $pemakaian, 2),
                'riwayat' => $baris->map(fn ($a) => [
                    'date' => optional($a->alloc_date)->toDateString(),
                    'description' => $a->description,
                    'amount' => (float) $a->amount,
                    'bank' => $a->cashAccount ? trim($a->cashAccount->code.' — '.$a->cashAccount->name) : null,
                ])->values(),
            ];
        });

        return Inertia::render('BusinessSupport/CashBook/Advances/CardBalance', [
            'rows' => $rows->values(),
        ]);
    }

    /** Kartu Bon Sementara — kartu per pemegang bon (mutasi + outstanding). */
    public function card(Request $request): Response
    {
        abort_unless($request->user()?->can('cashbook.advance.card'), 403);

        // Semua baris dikirim → filter per-kolom & ringkasan dihitung client-side (reaktif terhadap filter).
        $rows = BsCashAdvance::query()
            ->with('costCenter:id,name')
            ->orderByDesc('advance_date')->orderByDesc('id')->get()
            ->map(function ($a) {
                $s = SaldoBonSementara::dariBon($a);

                return [
                    'no' => $a->advance_number,
                    'date' => optional($a->advance_date)->toDateString(),
                    'holder' => $a->holder,
                    'department' => $a->costCenter?->name,
                    'purpose' => $a->purpose,
                    'amount' => $s['kasbon_awal'],
                    'realized' => $s['realisasi'],
                    'refund' => $s['refund'],
                    'unused' => $s['tidak_terpakai'],
                    'outstanding' => $s['outstanding'],
                    'status' => $a->status,
                ];
            });

        return Inertia::render('BusinessSupport/CashBook/Advances/Card', [
            'rows' => $rows->values(),
            'statuses' => BsCashAdvance::STATUSES,
        ]);
    }

    /** #9: query baris Kartu Bon Sementara (mengikuti filter layar) untuk Excel/PDF — reuse logika card(). */
    private function cardExport(Request $request): array
    {
        $statuses = array_values(array_filter((array) $request->input('statuses', []), fn ($v) => $v !== '' && $v !== null));
        $from = $request->input('from') ?: null;
        $to = $request->input('to') ?: null;

        // Checklist kolom (No Bon, Pemegang, Keperluan). Pilihan "" = sel kosong di layar,
        // jadi dicocokkan ke NULL/'' — bukan dibuang (dulu memilih "(kosong)" saja malah
        // mengunduh semua baris).
        $pilih = function ($q, string $kolom, string $param) use ($request) {
            $v = array_map(fn ($x) => (string) $x, (array) $request->input($param, []));
            if (! $v) {
                return $q;
            }
            $isi = array_values(array_filter($v, fn ($x) => $x !== ''));

            return $q->where(fn ($w) => $w->whereIn($kolom, $isi)
                ->when(in_array('', $v, true), fn ($x) => $x->orWhereNull($kolom)->orWhere($kolom, '')));
        };

        $rows = BsCashAdvance::query()
            ->with('costCenter:id,name')
            ->tap(fn ($q) => $pilih($q, 'advance_number', 'nos'))
            ->tap(fn ($q) => $pilih($q, 'holder', 'holders'))
            ->tap(fn ($q) => $pilih($q, 'purpose', 'purposes'))
            ->when($statuses, fn ($q) => $q->whereIn('status', $statuses))
            ->when($from, fn ($q) => $q->whereDate('advance_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('advance_date', '<=', $to))
            ->orderByDesc('advance_date')->orderByDesc('id')->get()
            ->map(function ($a) {
                $s = SaldoBonSementara::dariBon($a);

                return [
                    'no' => $a->advance_number,
                    'date' => optional($a->advance_date)->toDateString(),
                    'holder' => $a->holder,
                    'department' => $a->costCenter?->name,
                    'purpose' => $a->purpose,
                    'amount' => $s['kasbon_awal'],
                    'realized' => $s['realisasi'],
                    'refund' => $s['refund'],
                    'unused' => $s['tidak_terpakai'],
                    'outstanding' => $s['outstanding'],
                    'status' => BsCashAdvance::STATUSES[$a->status] ?? $a->status,
                ];
            });

        return ['rows' => $rows, 'total' => [
            'amount' => (float) $rows->sum('amount'),
            'realized' => (float) $rows->sum('realized'),
            'refund' => (float) $rows->sum('refund'),
            'unused' => (float) $rows->sum('unused'),
            'outstanding' => (float) $rows->sum('outstanding'),
            'count' => $rows->count(),
        ]];
    }

    /** #9: Kartu Bon Sementara → Excel (.xls). */
    public function cardExcel(Request $request): HttpResponse
    {
        abort_unless($request->user()?->can('cashbook.advance.card'), 403);

        $d = $this->cardExport($request);
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $rp = fn ($n) => number_format((float) $n, 0, ',', '.');

        // 11 kolom: 5 keterangan + 5 nilai + status. colspan judul harus ikut, kalau tidak judulnya
        // berhenti sebelum kolom terakhir (dulu colspan=8 di atas tabel 9 kolom).
        $h = '<table border="1"><tr><th colspan="11" style="background:#0f766e;color:#fff">Kartu Bon Sementara</th></tr>'
            .'<tr style="background:#e2e8f0"><th>No Bon</th><th>Tanggal</th><th>Pemegang</th><th>Departemen</th><th>Keperluan</th>'
            .'<th>Kasbon Awal</th><th>Realisasi</th><th>Refund</th><th>Tidak Terpakai</th><th>Outstanding</th><th>Status</th></tr>';
        foreach ($d['rows'] as $r) {
            $h .= '<tr><td>'.$e($r['no']).'</td><td>'.$e($r['date']).'</td><td>'.$e($r['holder']).'</td><td>'.$e($r['department'])
                .'</td><td>'.$e($r['purpose']).'</td><td>'.$rp($r['amount']).'</td><td>'.$rp($r['realized']).'</td><td>'.$rp($r['refund'])
                .'</td><td>'.$rp($r['unused']).'</td><td>'.$rp($r['outstanding']).'</td><td>'.$e($r['status']).'</td></tr>';
        }
        $t = $d['total'];
        $h .= '<tr style="background:#e2e8f0"><td colspan="5"><b>TOTAL ('.$t['count'].' bon)</b></td><td><b>'.$rp($t['amount']).'</b></td><td><b>'.$rp($t['realized'])
            .'</b></td><td><b>'.$rp($t['refund']).'</b></td><td><b>'.$rp($t['unused']).'</b></td><td><b>'.$rp($t['outstanding']).'</b></td><td></td></tr></table>';
        $file = 'Kartu-Bon-Sementara-'.now()->format('Ymd-His').'.xls';

        return response('<html xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="utf-8"></head><body>'.$h.'</body></html>')
            ->header('Content-Type', 'application/vnd.ms-excel; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="'.$file.'"');
    }

    /** #9: Kartu Bon Sementara → PDF (HTML A4 auto-print). */
    public function cardPdf(Request $request): HttpResponse
    {
        abort_unless($request->user()?->can('cashbook.advance.card'), 403);

        $d = $this->cardExport($request);
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $rp = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.');
        $co = (string) config('business_support.company.name', 'PT. MITRA USAHA PROPERTINDO');
        $now = now()->translatedFormat('d F Y H:i');

        $body = '';
        foreach ($d['rows'] as $r) {
            $body .= '<tr><td>'.$e($r['no']).'</td><td>'.$e($r['date']).'</td><td>'.$e($r['holder']).'</td><td>'.$e($r['department'])
                .'</td><td>'.$e($r['purpose']).'</td><td class="r">'.$rp($r['amount']).'</td><td class="r">'.$rp($r['realized'])
                .'</td><td class="r">'.$rp($r['refund']).'</td><td class="r">'.$rp($r['unused']).'</td><td class="r">'.$rp($r['outstanding']).'</td><td>'.$e($r['status']).'</td></tr>';
        }
        if ($body === '') {
            $body = '<tr><td colspan="11" class="c muted">Tidak ada data.</td></tr>';
        }
        $t = $d['total'];

        $html = '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><title>Kartu Bon Sementara</title><style>
            @page{size:A4 landscape;margin:12mm}*{box-sizing:border-box}body{font-family:"Segoe UI",Arial,sans-serif;font-size:11px;color:#1f2937;margin:0}
            h1{font-size:16px;margin:0 0 2px;color:#0f766e}.muted{color:#6b7280;font-weight:normal;font-size:10px}
            .head{border-bottom:2px solid #0f766e;padding-bottom:6px;margin-bottom:10px;display:flex;justify-content:space-between}
            table{border-collapse:collapse;width:100%;margin:4px 0;font-size:10px}th,td{border:1px solid #cbd5e1;padding:4px 6px;text-align:left}
            thead th{background:#f1f5f9}.r{text-align:right;font-variant-numeric:tabular-nums}.c{text-align:center}tfoot td{font-weight:bold;background:#f8fafc}
            @media print{.noprint{display:none}}</style></head><body>
            <div class="head"><div><h1>KARTU BON SEMENTARA</h1><div class="muted">'.$e($co).'</div></div><div class="muted" style="text-align:right">Cetak: '.$e($now).'</div></div>
            <table><thead><tr><th>No Bon</th><th>Tanggal</th><th>Pemegang</th><th>Departemen</th><th>Keperluan</th><th class="r">Kasbon Awal</th><th class="r">Realisasi</th><th class="r">Refund</th><th class="r">Tidak Terpakai</th><th class="r">Outstanding</th><th>Status</th></tr></thead>
            <tbody>'.$body.'</tbody>
            <tfoot><tr><td colspan="5">TOTAL ('.$t['count'].' bon)</td><td class="r">'.$rp($t['amount']).'</td><td class="r">'.$rp($t['realized']).'</td><td class="r">'.$rp($t['refund']).'</td><td class="r">'.$rp($t['unused']).'</td><td class="r">'.$rp($t['outstanding']).'</td><td></td></tr></tfoot></table>
            <button class="noprint" onclick="window.print()" style="margin-top:12px;padding:6px 14px">Cetak / Simpan PDF</button>
            <script>window.onload=function(){setTimeout(function(){window.print()},300)}</script></body></html>';

        return response($html)->header('Content-Type', 'text/html; charset=UTF-8');
    }

    /** #88: laporan bon sementara — 4 bucket (transfer & settlement). #150: filter departemen. */
    public function report(Request $request): Response
    {
        abort_unless($request->user()?->can('cashbook.advance.report'), 403);

        $department = $request->input('department') ?: null; // #150

        return Inertia::render('BusinessSupport/CashBook/Advances/Report', [
            'buckets' => $this->reportBuckets($department ? (int) $department : null),
            'departments' => $this->departments(),
            'filters' => ['department' => $department ? (int) $department : null],
        ]);
    }

    /** #175 — filter unduhan dari query (mengikuti filter layar: nama pemegang, keperluan, rentang tgl). */
    private function reportFilters(Request $request): array
    {
        $arr = function ($v): array {
            if (is_array($v)) {
                return $v;
            }

            return ($v === null || $v === '') ? [] : [$v];
        };

        return [
            'holders' => $arr($request->input('holders')),
            'purposes' => $arr($request->input('purposes')),
            'from' => $request->input('from') ?: null,
            'to' => $request->input('to') ?: null,
        ];
    }

    /** #126 — unduh laporan bon sementara (Excel .xls). */
    public function reportExcel(Request $request): HttpResponse
    {
        abort_unless($request->user()?->can('cashbook.advance.report.excel'), 403);

        $department = $request->input('department') ?: null; // #150
        $f = $this->reportFilters($request);                 // #175 ikut filter layar
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $rp = fn ($n) => number_format((float) $n, 0, ',', '.');
        $h = '';
        foreach ($this->reportBuckets($department ? (int) $department : null, $f) as $b) {
            // Kolom Aging hanya untuk bucket yang punya tanggal acuan — sama persis dengan layar.
            $aging = (bool) ($b['aging'] ?? false);
            $cols = $aging ? 8 : 7;
            $h .= '<table border="1"><tr><th colspan="'.$cols.'" style="background:#0f766e;color:#fff">'.$e($b['label']).' — '.$e($rp($b['total'])).' ('.$b['count'].' bon)</th></tr>'
                .'<tr style="background:#e2e8f0"><th>No.</th><th>Tgl Bon</th><th>Tgl Realisasi</th><th>Pemegang</th><th>Departemen</th><th>Keperluan</th>'
                .($aging ? '<th>Aging (hari)<br><span style="font-weight:normal">'.$e($b['aging_label']).'</span></th>' : '')
                .'<th>Nilai</th></tr>';
            foreach ($b['rows'] as $r) {
                $h .= '<tr><td>'.$e($r['advance_number']).'</td><td>'.$e($r['advance_date']).'</td><td>'.$e($r['realized_date']).'</td><td>'.$e($r['holder']).'</td><td>'.$e($r['department']).'</td><td>'.$e($r['purpose']).'</td>'
                    // Angka mentah (tanpa " hari") agar bisa langsung difilter/dihitung di Excel.
                    .($aging ? '<td>'.($r['aging_days'] === null ? '' : (int) $r['aging_days']).'</td>' : '')
                    .'<td>'.$rp($r['amount']).'</td></tr>';
            }
            $h .= '</table><br>';
        }
        $file = 'Rekap-Bon-Sementara-'.now()->format('Ymd-His').'.xls';

        return response('<html xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="utf-8"></head><body><h3>REKAP BON SEMENTARA</h3>'.$h.'</body></html>')
            ->header('Content-Type', 'application/vnd.ms-excel; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="'.$file.'"');
    }

    /** #126 — laporan bon sementara siap-cetak (PDF via dialog cetak browser). */
    public function reportPdf(Request $request): HttpResponse
    {
        abort_unless($request->user()?->can('cashbook.advance.report.pdf'), 403);

        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $rp = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.');
        $co = (string) config('business_support.company.name', 'PT. MITRA USAHA PROPERTINDO');
        $now = now()->translatedFormat('d F Y H:i');
        $department = $request->input('department') ?: null; // #150
        $deptName = $department ? BsCostCenter::whereKey($department)->value('name') : null;
        $f = $this->reportFilters($request);                 // #175 ikut filter layar
        // #175: tampilkan ringkas filter nama/keperluan pada header bila ada.
        $holderHint = ! empty($f['holders']) ? ' · Pemegang: '.implode(', ', $f['holders']) : '';

        $sections = '';
        foreach ($this->reportBuckets($department ? (int) $department : null, $f) as $b) {
            // Kolom Aging hanya untuk bucket yang punya tanggal acuan — sama persis dengan layar.
            $aging = (bool) ($b['aging'] ?? false);
            $cols = $aging ? 7 : 6;
            $rows = '';
            foreach ($b['rows'] as $r) {
                $rows .= '<tr><td>'.$e($r['advance_number']).'</td><td>'.$e($r['advance_date']).'</td><td>'.$e($r['holder']).'</td><td>'.$e($r['department']).'</td><td>'.$e($r['purpose']).'</td>'
                    .($aging ? '<td class="r">'.($r['aging_days'] === null ? '—' : (int) $r['aging_days'].' hari').'</td>' : '')
                    .'<td class="r">'.$rp($r['amount']).'</td></tr>';
            }
            if ($rows === '') {
                $rows = '<tr><td colspan="'.$cols.'" class="c muted">Tidak ada data.</td></tr>';
            }
            $sections .= '<h3>'.$e($b['label']).' <span class="muted">('.$b['count'].' bon · '.$rp($b['total']).')</span></h3>'
                .'<table><thead><tr><th>No.</th><th>Tanggal</th><th>Pemegang</th><th>Departemen</th><th>Keperluan</th>'
                .($aging ? '<th class="r">Aging<br><span class="muted">'.$e($b['aging_label']).'</span></th>' : '')
                .'<th class="r">Nilai</th></tr></thead><tbody>'.$rows.'</tbody></table>';
        }

        $html = '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><title>Rekap Bon Sementara</title><style>
            @page{size:A4;margin:14mm}*{box-sizing:border-box}body{font-family:"Segoe UI",Arial,sans-serif;font-size:11px;color:#1f2937;margin:0}
            h1{font-size:16px;margin:0 0 2px;color:#b45309}h3{font-size:12px;margin:14px 0 4px;color:#92400e}.muted{color:#6b7280;font-weight:normal;font-size:10px}
            .head{border-bottom:2px solid #b45309;padding-bottom:6px;margin-bottom:10px;display:flex;justify-content:space-between}
            table{border-collapse:collapse;width:100%;margin:4px 0;font-size:10px}th,td{border:1px solid #cbd5e1;padding:4px 6px;text-align:left}
            .r{text-align:right;font-variant-numeric:tabular-nums}.c{text-align:center}@media print{.noprint{display:none}}</style></head><body>
            <div class="head"><div><h1>REKAP BON SEMENTARA</h1><div class="muted">'.$e($co).($deptName ? ' · Departemen: '.$e($deptName) : '').$e($holderHint).'</div></div><div class="muted" style="text-align:right">Cetak: '.$e($now).'</div></div>'
            .$sections.
            '<button class="noprint" onclick="window.print()" style="margin-top:12px;padding:6px 14px">Cetak / Simpan PDF</button>
            <script>window.onload=function(){setTimeout(function(){window.print()},300)}</script></body></html>';

        return response($html)->header('Content-Type', 'text/html; charset=UTF-8');
    }

    /** Bangun 4 bucket laporan bon sementara (dipakai report/reportExcel/reportPdf).
     *  #150: bila $departmentId diberikan, batasi ke departemen pemegang bon tsb.
     *  #175: $f = filter tambahan agar Excel/PDF MENGIKUTI filter layar (nama pemegang, keperluan,
     *        rentang tanggal bon) — sehingga unduhan "cukup data" sesuai yang difilter di layar. */
    private function reportBuckets(?int $departmentId = null, array $f = []): array
    {
        $holders = array_values(array_filter((array) ($f['holders'] ?? []), fn ($v) => $v !== '' && $v !== null));
        $purposes = array_values(array_filter((array) ($f['purposes'] ?? []), fn ($v) => $v !== '' && $v !== null));
        $from = $f['from'] ?? null;
        $to = $f['to'] ?? null;

        // Aging = umur bon dalam hari s/d HARI INI, dihitung dari tanggal yang berbeda per bucket:
        //   Outstanding (Sudah Ditransfer) → dari paid_date (tanggal Finance membayar bon)
        //   Belum Direfund / Direimburse   → dari received_document_date (tanggal berkas diterima)
        // Basisnya sengaja beda karena "umur" yang ditunggu pun beda: yang satu uang sudah keluar
        // tapi belum dipertanggungjawabkan, yang satu berkas sudah masuk tapi belum diselesaikan.
        // Tanggal acuan boleh NULL (belum diisi) → aging null, ditampilkan "—", bukan 0 hari.
        $today = now()->startOfDay();
        $agingOf = fn (?\Illuminate\Support\Carbon $d) => $d
            ? (int) $d->copy()->startOfDay()->diffInDays($today, false)
            : null;

        $map = fn (string $agingFrom) => fn ($a) => [
            'id' => $a->id,
            'advance_number' => $a->advance_number,
            'advance_date' => $a->advance_date?->toDateString(),
            'paid_date' => $a->paid_date?->toDateString(),
            'realized_date' => $a->realized_date?->toDateString(),
            'received_document_date' => $a->received_document_date?->toDateString(),
            'holder' => $a->holder,
            'department_id' => $a->cost_center_id,
            'department' => $a->costCenter?->name,
            'purpose' => $a->purpose,
            'amount' => (float) $a->amount,
            'settlement' => (float) $a->settlement,
            // Tanggal acuan aging + hasilnya, agar layar/Excel tak perlu tahu aturannya.
            'aging_from' => $agingFrom,
            'aging_base_date' => $a->{$agingFrom}?->toDateString(),
            'aging_days' => $agingOf($a->{$agingFrom}),
        ];

        // #150: query dasar + filter departemen bila ada. "Departemen" di laporan ini
        // BERSUMBER cost center: master Departemen tersendiri sudah dicabut karena tidak
        // pernah diisi, sehingga penyaring ini dulu selalu kosong. Namanya tetap Departemen.
        // #175: + filter nama pemegang (exact, mengikuti pilihan dropdown), keperluan, & rentang tgl bon.
        $base = fn () => BsCashAdvance::query()->with('costCenter:id,name')
            ->when($departmentId, fn ($q) => $q->where('cost_center_id', $departmentId))
            ->when($holders, fn ($q) => $q->whereIn('holder', $holders))
            ->when($purposes, fn ($q) => $q->whereIn('purpose', $purposes))
            ->when($from, fn ($q) => $q->whereDate('advance_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('advance_date', '<=', $to));

        $sudahTransfer = $base()->where('status', 'outstanding')->latest('advance_date')->get();
        $belumTransfer = $base()->where('status', 'requested')->latest('advance_date')->get();
        $belumRefund = $base()->where('status', 'realized')->where('settlement_status', 'pending')
            ->where('settlement', '>', 0)->latest('realized_date')->get();
        $belumReimburse = $base()->where('status', 'realized')->where('settlement_status', 'pending')
            ->where('settlement', '<', 0)->latest('realized_date')->get();

        // $agingFrom = null → bucket tanpa kolom aging (Belum Ditransfer: uang belum keluar & berkas
        // belum ada, tidak ada yang wajar dihitung umurnya).
        $bucket = fn (string $label, string $desc, $rows, string $amountKey = 'amount', ?string $agingFrom = null) => [
            'label' => $label,
            'desc' => $desc,
            'rows' => $rows->map($agingFrom ? $map($agingFrom) : $map('advance_date'))->values(),
            'count' => $rows->count(),
            'aging' => $agingFrom !== null,
            'aging_label' => match ($agingFrom) {
                'paid_date' => 'Sejak tanggal bayar bon',
                'received_document_date' => 'Sejak tanggal berkas diterima',
                default => null,
            },
            'total' => $amountKey === 'amount'
                ? (float) $rows->sum('amount')
                : (float) $rows->sum(fn ($a) => abs((float) $a->settlement)),
        ];

        return [
            'sudah_transfer' => $bucket('Outstanding (Sudah Ditransfer)', 'Bon sudah dibayar Finance, belum direalisasi.', $sudahTransfer, 'amount', 'paid_date'),
            'belum_transfer' => $bucket('Outstanding (Belum Ditransfer)', 'Pengajuan bon menunggu pembayaran Finance.', $belumTransfer),
            'belum_refund' => $bucket('Realisasi — Belum Direfund', 'Sisa panjar berlebih belum dikembalikan ke kas.', $belumRefund, 'settlement', 'received_document_date'),
            'belum_reimburse' => $bucket('Realisasi — Belum Direimburse', 'Kekurangan panjar belum diganti dari kas.', $belumReimburse, 'settlement', 'received_document_date'),
        ];
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()?->can('cashbook.advance.create'), 403);

        // Kolom Departemen dihapus dari layar pengajuan, jadi daftar departemen & department_id
        // karyawan tidak lagi dikirim ke sana. Departemennya tetap tercatat — ditentukan
        // CashAdvanceService::issue() dari karyawan yang dipilih (fallback #150), sehingga
        // penyaring Departemen pada Laporan Bon Sementara tetap berisi seperti sebelumnya.
        // KARYAWAN & COST CENTER SAMA-SAMA DITURUNKAN DARI AKUN YANG LOGIN.
        //
        // Bon sementara adalah uang yang dipegang orangnya sendiri; memilih nama lain berarti
        // mengajukan bon atas nama orang lain, dan unit kerja yang dibebani ikut berpindah.
        // Karena itu daftar karyawan TIDAK dikirim ke layar sama sekali — bukan sekadar
        // dikunci, sebab kiriman langsung masih bisa menyebut id lain. Penegakan sebenarnya
        // ada di store(), yang mengabaikan employee_id dari layar.
        $emp = BsEmployee::with('costCenter:id,code,name')
            ->whereKey(app(CostCenterPengguna::class)->idKaryawan($request->user()))
            ->first();

        return Inertia::render('BusinessSupport/CashBook/Advances/Form', [
            'karyawan' => $emp === null ? null : [
                'id' => $emp->id,
                'name' => $emp->name,
                'position' => $emp->position,
                // Dipulangkan hanya untuk MENJELASKAN penolakan bila kosong, bukan ditampilkan
                // sebagai isian.
                'punya_cost_center' => $emp->cost_center_id !== null,
            ],
            // Departemen yang boleh dibebani bon ini: milik karyawan sendiri (Utama lebih dulu).
            // Lebih dari satu → layar menampilkan pilihan; satu → langsung terpakai seperti dulu.
            'departemen' => $emp === null ? [] : $this->opsiDepartemen($emp),
            'rekening' => $emp === null ? [] : $this->opsiRekening($emp),
        ]);
    }

    /**
     * Cost center baris Realisasi: ccPengguna = bawaan, ccPilihan = departemen yang boleh dipilih.
     *
     * Sumbernya PEMILIK BON: akun yang mengajukannya (created_by — sejak 20 Okt bon selalu
     * diajukan dari akun karyawannya sendiri), lalu nama pemegangnya; tak ketemu → akun yang
     * login, seperti sebelumnya. Bawaan = cost center bon itu bila termasuk daftarnya, kalau
     * tidak Utama. (rek_employee_id BUKAN pemilik: itu pemilik rekening tujuan, bisa atasannya.)
     *
     * @return array{ccPengguna: ?int, ccPilihan: list<int>}
     */
    private function ccRealisasi(Request $request, BsCashAdvance $advance): array
    {
        $cc = app(CostCenterPengguna::class);
        $pengaju = $advance->created_by ? \App\Models\User::find($advance->created_by) : null;
        $daftar = $pengaju ? $cc->idsUntuk($pengaju) : [];
        if ($daftar === [] && trim((string) $advance->holder) !== '') {
            // Bon lama: holder "Nama" atau "Nama — Jabatan".
            $nama = trim(explode(' — ', (string) $advance->holder)[0]);
            $daftar = BsEmployee::whereRaw('LOWER(name) = ?', [mb_strtolower($nama)])->first()?->costCenterIds() ?? [];
        }
        if ($daftar === []) {
            $daftar = $cc->idsUntuk($request->user());
        }
        $bon = $advance->cost_center_id ? (int) $advance->cost_center_id : null;

        return [
            'ccPengguna' => ($bon !== null && in_array($bon, $daftar, true)) ? $bon : ($daftar[0] ?? null),
            'ccPilihan' => $daftar,
        ];
    }

    /**
     * Departemen milik karyawan (Utama lebih dulu) sebagai pilihan bon.
     *
     * @return list<array{id:int, code:?string, name:?string}>
     */
    private function opsiDepartemen(BsEmployee $emp): array
    {
        $ids = $emp->costCenterIds();
        $cc = BsCostCenter::whereIn('id', $ids)->get(['id', 'code', 'name'])->keyBy('id');

        return array_values(array_filter(array_map(fn (int $id) => isset($cc[$id])
            ? ['id' => $id, 'code' => $cc[$id]->code, 'name' => $cc[$id]->name]
            : null, $ids)));
    }

    /**
     * Pilihan rekening tujuan transfer bon: rekening pengaju sendiri dan rekening atasannya
     * (Data Karyawan → Atasan). Dibaca dari master SAAT INI — yang dipilih lalu DISALIN ke bon.
     *
     * @return array<string, array{employee_id:int, nama:string, jabatan:?string, bank:?string, nomor:?string, ada:bool}>
     */
    private function opsiRekening(BsEmployee $emp): array
    {
        $baris = fn (BsEmployee $e) => [
            'employee_id' => (int) $e->id,
            'nama' => trim((string) $e->name),
            'jabatan' => $e->position ? trim((string) $e->position) : null,
            'bank' => trim((string) $e->bank_name) ?: null,
            'nomor' => trim((string) $e->bank_account) ?: null,
            'ada' => trim((string) $e->bank_account) !== '',
        ];

        $opsi = ['sendiri' => $baris($emp)];
        if ($emp->atasan_id && ($atasan = BsEmployee::find($emp->atasan_id))) {
            $opsi['atasan'] = $baris($atasan);
        }

        return $opsi;
    }

    public function store(Request $request, CashAdvanceService $svc): RedirectResponse
    {
        abort_unless($request->user()?->can('cashbook.advance.create'), 403);

        // #49: pengajuan saja — tanpa sumber kas/bank & tanpa jurnal (Finance yang membayar).
        // #150: employee_id (opsional). Departemen tidak lagi dikirim — cost_center_id
        // yang wajib itulah yang dibaca laporan sebagai departemen.
        $v = $request->validate([
            'advance_date' => ['required', 'date'],
            'holder' => ['required', 'string', 'max:150'],
            'purpose' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            // Cara persetujuan dipilih per pengajuan; kosong = online (perilaku lama).
            'approval_mode' => ['nullable', 'string', 'in:'.implode(',', array_keys(BsCashAdvance::APPROVAL_MODES))],
            'rek_sumber' => ['nullable', 'string', 'in:sendiri,atasan'],
            'cost_center_id' => ['nullable', 'integer'],
        ]);
        $ccDipilih = ($v['cost_center_id'] ?? null) ? (int) $v['cost_center_id'] : null;
        unset($v['cost_center_id']);

        // COST CENTER DITURUNKAN dari karyawan pengaju, tidak lagi dikirim layar. Kalau
        // karyawannya belum punya unit kerja, pengajuan DITOLAK dengan menyebut apa yang
        // harus dibereskan — bon tanpa cost center tetap jadi beban di GL tetapi tidak
        // pernah terbaca sebagai realisasi di Budget Control, dan itu baru ketahuan
        // berbulan kemudian saat anggaran direkonsiliasi.
        // KARYAWAN DARI AKUN YANG LOGIN, bukan dari kiriman layar. employee_id yang ikut
        // terkirim SENGAJA DIABAIKAN: mengunci pilihan di layar saja tidak menahan kiriman
        // langsung, dan bon atas nama orang lain membebani unit kerja yang tidak
        // menanggungnya.
        $emp = BsEmployee::with('costCenter:id,code,name')
            ->whereKey(app(CostCenterPengguna::class)->idKaryawan($request->user()))
            ->first();

        if ($emp === null) {
            return back()->withInput()->with('error',
                'Akun Anda belum tertaut ke data karyawan. Minta Admin melengkapi NIP karyawan pada akun ini.');
        }

        if ($emp->cost_center_id === null) {
            return back()->withInput()->with('error',
                'Karyawan '.$emp->name.' belum punya Cost Center di master Karyawan. '
                .'Lengkapi dulu di HR, lalu ulangi pengajuan.');
        }

        $v['employee_id'] = (int) $emp->id;
        // Pemegang = NAMA karyawan akun yang login, tanpa jabatan, ditentukan server — bukan
        // teks kiriman layar. Bon lama ("Nama — Jabatan") tetap terbaca AksesBon & cetakan.
        $v['holder'] = trim((string) $emp->name);
        // Departemen: yang dipilih di layar, tetapi HANYA dari departemen milik karyawan ini —
        // kiriman di luar daftarnya ditolak, bukan diam-diam diganti Utama. Kosong = Utama.
        if ($ccDipilih !== null && ! in_array($ccDipilih, $emp->costCenterIds(), true)) {
            return back()->withInput()->with('error',
                'Departemen yang dipilih bukan departemen karyawan '.$emp->name.'. Pilih dari daftar departemennya.');
        }
        $v['cost_center_id'] = $ccDipilih ?? (int) $emp->cost_center_id;

        $rek = $this->salinRekening($emp, $v['rek_sumber'] ?? null);
        unset($v['rek_sumber']);
        if (is_string($rek)) {
            return back()->withInput()->with('error', $rek);
        }
        $v += $rek;

        try {
            $adv = $svc->issue($v, $request->user()->id);
        } catch (BusinessRuleException $e) {
            // Penolakan anggaran → DIALOG (lihat trait GalatAnggaran); selebihnya toast.
            return $this->balikDenganGalat($e);
        }

        return redirect()->route('bs.advance.show', $adv)->with('success', "Pengajuan Bon Sementara {$adv->advance_number} tercatat — menunggu pembayaran Finance (jurnal belum terbentuk).");
    }

    /**
     * REKENING TUJUAN — nomornya diambil server dari master, bukan dari kiriman layar
     * (layar hanya menyebut "sendiri"/"atasan"), lalu disalin ke bon.
     *
     * @return array<string,mixed>|string kolom rek_* untuk bon, atau pesan galat
     */
    private function salinRekening(BsEmployee $emp, ?string $sumber): array|string
    {
        $opsiRek = $this->opsiRekening($emp);
        $sumber ??= ($opsiRek['sendiri']['ada'] ?? false) ? 'sendiri' : null;
        if ($sumber === null) {
            return [];
        }
        $rek = $opsiRek[$sumber] ?? null;
        if (! $rek || ! $rek['ada']) {
            return $sumber === 'atasan'
                ? 'Rekening atasan belum tersedia — isi Atasan dan rekeningnya di Data Karyawan.'
                : 'Rekening karyawan belum diisi di Data Karyawan.';
        }

        return ['rek_sumber' => $sumber, 'rek_employee_id' => $rek['employee_id'],
            'rek_bank' => $rek['bank'], 'rek_nomor' => $rek['nomor'], 'rek_atas_nama' => $rek['nama']];
    }

    /**
     * Karyawan PEMILIK BON — akun pengajunya (created_by), lalu nama pemegangnya. Pilihan
     * departemen & rekening pada Edit Pengajuan dibaca dari dia, bukan dari akun yang mengedit:
     * bon yang diubah orang Finance tetap bon milik pengajunya.
     */
    private function karyawanPemilikBon(BsCashAdvance $advance): ?BsEmployee
    {
        $pengaju = $advance->created_by ? \App\Models\User::find($advance->created_by) : null;
        $id = $pengaju ? app(CostCenterPengguna::class)->idKaryawan($pengaju) : null;
        if ($id !== null) {
            return BsEmployee::find($id);
        }
        $nama = trim(explode(' — ', (string) $advance->holder)[0]);

        return $nama === '' ? null : BsEmployee::whereRaw('LOWER(name) = ?', [mb_strtolower($nama)])->first();
    }

    /** Edit Pengajuan — hanya selagi Diajukan (belum dibayar Finance). */
    public function edit(Request $request, BsCashAdvance $advance): Response|RedirectResponse
    {
        abort_unless($request->user()?->can('cashbook.advance.update'), 403);
        abort_unless($this->bolehBukaBon($request, $advance), 403);
        if ($advance->status !== 'requested') {
            return redirect()->route('bs.advance.show', $advance)->with('error', 'Bon yang sudah dibayar tidak bisa diubah — mundurkan dulu lewat Batal Pembayaran.');
        }

        $emp = $this->karyawanPemilikBon($advance);

        return Inertia::render('BusinessSupport/CashBook/Advances/Form', [
            'karyawan' => $emp === null ? null : [
                'id' => $emp->id, 'name' => $emp->name, 'position' => $emp->position,
                'punya_cost_center' => $emp->cost_center_id !== null,
            ],
            'departemen' => $emp === null ? [] : $this->opsiDepartemen($emp),
            'rekening' => $emp === null ? [] : $this->opsiRekening($emp),
            'advance' => $advance->only(['id', 'advance_number', 'advance_date', 'holder', 'purpose', 'amount',
                'approval_mode', 'rek_sumber', 'cost_center_id', 'approvals']),
        ]);
    }

    public function update(Request $request, BsCashAdvance $advance, CashAdvanceService $svc): RedirectResponse
    {
        abort_unless($request->user()?->can('cashbook.advance.update'), 403);
        abort_unless($this->bolehBukaBon($request, $advance), 403);

        $v = $request->validate([
            'advance_date' => ['required', 'date'],
            'purpose' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'approval_mode' => ['nullable', 'string', 'in:'.implode(',', array_keys(BsCashAdvance::APPROVAL_MODES))],
            'rek_sumber' => ['nullable', 'string', 'in:sendiri,atasan'],
            'cost_center_id' => ['nullable', 'integer'],
        ]);

        $emp = $this->karyawanPemilikBon($advance);
        if ($emp === null) {
            return back()->with('error', 'Pemilik bon ini tidak tertaut ke data karyawan — departemen & rekeningnya tidak bisa ditentukan.');
        }
        // Departemen hanya dari milik karyawan pemilik bon (aturan yang sama dengan pengajuan).
        $ccDipilih = ($v['cost_center_id'] ?? null) ? (int) $v['cost_center_id'] : (int) $advance->cost_center_id;
        if (! in_array($ccDipilih, $emp->costCenterIds(), true)) {
            return back()->with('error', 'Departemen yang dipilih bukan departemen karyawan '.$emp->name.'.');
        }
        $v['cost_center_id'] = $ccDipilih;

        $rek = $this->salinRekening($emp, $v['rek_sumber'] ?? null);
        unset($v['rek_sumber']);
        if (is_string($rek)) {
            return back()->with('error', $rek);
        }
        $v += $rek;

        try {
            $svc->ubahPengajuan($advance, $v, $request->user()->id);
        } catch (BusinessRuleException $e) {
            return $this->balikDenganGalat($e);
        }

        return redirect()->route('bs.advance.show', $advance)->with('success', 'Pengajuan '.$advance->advance_number.' diperbarui.');
    }

    /** #49: daftar pengajuan bon sementara yang menunggu pembayaran Finance. */
    public function payments(Request $request): Response
    {
        abort_unless($request->user()?->can('cashbook.advance.view'), 403);

        $rows = BsCashAdvance::query()
            ->where('status', 'requested')
            ->latest('advance_date')->latest('id')
            ->paginate(25)->withQueryString();

        // Bayar hanya boleh bila approval lengkap → kirim status approval per baris.
        $rows->getCollection()->transform(function (BsCashAdvance $a) {
            $req = $a->requiredSigners();
            $a->setAttribute('is_fully_approved', $a->isFullyApproved());
            $a->setAttribute('required_count', count($req));
            $a->setAttribute('approved_count', count(array_intersect(array_keys($a->approvals ?? []), $req)));

            return $a;
        });

        return Inertia::render('BusinessSupport/CashBook/Advances/Payments', [
            'advances' => $rows,
            'cashAccounts' => $this->cashAccounts(),
            'summary' => [
                'count' => BsCashAdvance::where('status', 'requested')->count(),
                'total' => (float) BsCashAdvance::where('status', 'requested')->sum('amount'),
            ],
        ]);
    }

    /** #49: Finance mencatat pembayaran (tanggal transfer + sumber bank/kas) → jurnal terbentuk. */
    public function pay(Request $request, BsCashAdvance $advance, CashAdvanceService $svc): RedirectResponse
    {
        abort_unless($request->user()?->can('cashbook.advance.pay'), 403);

        $v = $request->validate([
            'paid_date' => ['required', 'date'],
            /* Sumber bank/kas hanya untuk pembayaran REGULER. Pembayaran Debit VA tidak membentuk
               jurnal apa pun — uangnya baru keluar dari bank saat Alokasi Kasbon dibuat, dan bank
               itu dipilih di sana. `required_unless` (bukan `required`) supaya layar bayar massal,
               yang tidak pernah mengirim payment_method, tetap wajib memilih banknya. */
            'cash_account_id' => ['nullable', 'integer', 'exists:bs_accounts,id', 'required_unless:payment_method,debit_va'],
            // Cara pembayaran; kosong = reguler (perilaku lama).
            'payment_method' => ['nullable', 'string', 'in:'.implode(',', array_keys(BsCashAdvance::PAYMENT_METHODS))],
            // Tanggal expired WAJIB pada Debit VA, dan tidak boleh mendahului tanggal transfer:
            // VA yang sudah lewat tanggalnya tidak bisa didebit, sehingga uangnya tak pernah
            // berpindah meski jurnalnya terbentuk.
            'va_expired_date' => ['nullable', 'date', 'required_if:payment_method,debit_va', 'after_or_equal:paid_date'],
            // Nomor kartu debit/VA yang didebit. WAJIB pada Debit VA: permintaan penambahan
            // saldo dan permintaan masa berlaku yang lahir dari bon ini harus menempel pada
            // kartu tertentu — tanpa itu, saldo bertambah di kartu yang tidak dituju.
            'credit_card_id' => ['nullable', 'integer', 'exists:bs_credit_cards,id', 'required_if:payment_method,debit_va'],
        ], [
            'cash_account_id.required_unless' => 'Sumber bank/kas wajib dipilih untuk pembayaran reguler.',
            'va_expired_date.required_if' => 'Tanggal expired VA wajib diisi untuk Pembayaran Debit VA.',
            'va_expired_date.after_or_equal' => 'Tanggal expired VA tidak boleh mendahului tanggal transfer.',
            'credit_card_id.required_if' => 'Nomor kartu debit/VA wajib dipilih untuk Pembayaran Debit VA.',
        ]);

        // Catat Pembayaran & Buat Jurnal hanya boleh setelah SELURUH persetujuan (pengaju → Manager Finance) lengkap.
        if (! $advance->isFullyApproved()) {
            return back()->with('error', 'Pembayaran ditolak — seluruh persetujuan bon (pengaju s.d. Manager Finance) harus lengkap terlebih dahulu.');
        }

        try {
            $svc->pay($advance, $v, $request->user()->id);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        $advance->refresh();

        return redirect()->route('bs.advance.show', $advance)->with('success', $advance->payment_method === 'debit_va'
            ? 'Pembayaran Debit VA tersimpan — belum ada jurnal. Saldonya masuk ke kartu/VA; isi Alokasi Kasbon untuk mengeluarkan uangnya dari bank.'
            : 'Bon sementara dibayar — jurnal Uang Muka Operasional terbentuk.');
    }

    /** Tambah satu baris Alokasi Kasbon (bon Debit VA) — Dr Uang Muka Operasional / Cr bank pengeluaran. */
    public function allocationStore(Request $request, BsCashAdvance $advance, CashAdvanceAllocationService $svc): RedirectResponse
    {
        abort_unless($request->user()?->can('cashbook.advance.realize'), 403);

        $v = $request->validate([
            'alloc_date' => ['required', 'date'],
            'description' => ['nullable', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'cash_account_id' => ['required', 'integer', 'exists:bs_accounts,id'],
        ], [
            'cash_account_id.required' => 'Pilih bank pengeluaran alokasi.',
        ]);

        try {
            $svc->tambah($advance, $v, $request->user()->id);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Alokasi kasbon tercatat — Dr Uang Muka Operasional / Cr bank pengeluaran. Alokasi bertanggal & berbank sama masuk ke satu jurnal.');
    }

    /** Hapus satu baris Alokasi Kasbon beserta jurnalnya (hanya selama bon belum direalisasi). */
    public function allocationDestroy(Request $request, BsCashAdvanceAllocation $allocation, CashAdvanceAllocationService $svc): RedirectResponse
    {
        abort_unless($request->user()?->can('cashbook.advance.realize'), 403);

        try {
            $svc->hapus($allocation, $request->user()->id);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Alokasi kasbon dihapus. Jurnalnya ikut terhapus bila tidak ada lagi alokasi lain pada tanggal & bank itu.');
    }

    public function show(Request $request, BsCashAdvance $advance): Response
    {
        abort_unless($request->user()?->can('cashbook.advance.view'), 403);
        abort_unless($this->bolehBukaBon($request, $advance), 403);

        $advance->load(['cashAccount:id,code,name', 'creditCard:id,name,card_number,balance,expired_date', 'lines.expenseAccount:id,code,name', 'lines.costCenter:id,code,name']);
        $this->buangBarisPengajuan($advance);

        return Inertia::render('BusinessSupport/CashBook/Advances/Show', [
            'advance' => $advance,
            // Nomor referensi jurnal realisasi (JKB/…) — nomor yang sama dengan "No. Voucher" di
            // Cetak Realisasi, supaya bonnya bisa ditelusuri ke buku besar. Tautan hanya bila
            // penggunanya memang boleh membuka jurnal.
            'jurnalRealisasi' => $advance->realize_journal_id
                ? ['id' => (int) $advance->realize_journal_id,
                    'number' => BsJournal::whereKey($advance->realize_journal_id)->value('journal_number'),
                    'bisaBuka' => (bool) $request->user()?->can('gl.journal.view')]
                : null,
            // Selain beban & aset, dibuka juga: TITIPAN ESTATE, PENYELESAIAN BIAYA KONSTRUKSI (kewajiban)
            // dan NURSERY ESTATE — biayanya dibayar dari dana titipan/cadangan tsb.
            'expenseAccounts' => BsAccount::where('is_active', true)->where('is_postable', true)
                ->where(fn ($q) => $q->whereIn('type', ['expense', 'asset'])
                    ->orWhere('name', 'like', 'TITIPAN ESTATE%')
                    ->orWhere('name', 'like', 'PENYELESAIAN BIAYA KONSTRUKSI%')
                    ->orWhere('code', '10104010199'))
                ->orderBy('code')->get(['id', 'code', 'name', 'type', 'cost_center_id', 'cost_center_optional'])
                ->pipe(fn ($c) => CostCenterAkun::lampirkan($c)),
            // Cost center per baris realisasi → diteruskan ke jurnal, dibaca Budget Control.
            'costCenters' => BsCostCenter::where('is_active', true)
                ->orderBy('code')->get(['id', 'code', 'name']),
            'cashAccounts' => $this->cashAccounts(),
            'signers' => BsCashAdvance::SIGNERS,                                       // #127
            'requiredSigners' => $advance->requiredSigners(),                                  // #127
            'fullyApproved' => $advance->isFullyApproved(),                                  // gate Catat Pembayaran
            // Layar perlu MEMBEDAKAN "belum ada yang menyetujui" dari "memang tidak lewat
            // aplikasi" — keduanya sama-sama tanpa tanda tangan digital, tetapi yang satu
            // menahan pembayaran dan yang satu tidak.
            'approvalMode' => $advance->isOfflineApproval() ? 'offline' : 'online',
            'approvalModes' => BsCashAdvance::APPROVAL_MODES,
            'paymentMethods' => BsCashAdvance::PAYMENT_METHODS,
            // Kartu debit/VA yang BOLEH dipakai membayar: aktif dan belum lewat masa berlaku.
            // Kartu nonaktif/expired sengaja tidak dikirim sama sekali — menampilkannya sebagai
            // pilihan yang ditolak server hanya memindahkan penolakannya ke belakang.
            'creditCards' => \App\Domain\BusinessSupport\Models\BsCreditCard::where('is_active', true)
                ->orderBy('name')->get(['id', 'name', 'card_number', 'balance', 'expired_date', 'is_active'])
                ->filter(fn ($c) => $c->isUsable())
                ->map(fn ($c) => ['id' => $c->id, 'label' => $c->label(), 'balance' => (float) $c->balance])
                ->values(),
            'canApprove' => (bool) $request->user()?->canAny(['cashbook.advance.approve', 'businesssupport.approval.act']), // #127
            // SELURUH PANEL DEPT FINANCE DIPISAH LEWAT RUTE, bukan izin.
            //
            // Penjagaan berbasis izin saja tidak cukup: SuperAdmin memegang semua izin, jadi
            // ia tetap melihat panel Finance saat membuka bonnya sendiri dari menu pengaju —
            // dan layar "milik pengaju" jadi tidak pernah benar-benar bersih.
            //
            // bs.advance.show        → tampilan PENGAJU: data, persetujuan, cetak. Baca-saja.
            // bs.advance.finance.show → tampilan FINANCE: tolak, bayar, alokasi, realisasi.
            //
            // Izinnya TETAP diperiksa di tiap aksinya (pay/realize/cancel) — rute hanya
            // menentukan apa yang DITAMPILKAN, bukan apa yang boleh dilakukan.
            'modeFinance' => $request->routeIs('bs.advance.finance.show')
                && (bool) $request->user()?->can('cashbook.advance.pay'),
            // IZINNYA DISAMAKAN DENGAN AKSINYA: setReceivedDate() menuntut
            // cashbook.advance.received-date, sedangkan isian ini dulu muncul untuk siapa pun
            // yang boleh MELIHAT bon — jadi kebanyakan orang disodori isian yang berakhir 403
            // begitu tanggalnya diubah.
            'canEditDocDate' => (bool) $request->user()?->can('cashbook.advance.received-date'), // #125
            // REALISASI milik PENGAJU; REFUND & REIMBURSE milik DEPT FINANCE (12 Okt 2026).
            // Realisasi digerbangi IZIN saja, sebab keduanya boleh melakukannya: pengaju yang
            // biasa, Finance sebagai pembetul bila pengaju salah isi. Refund/Reimburse tidak —
            // keduanya memindahkan uang masuk/keluar bank, dan yang memegang kas adalah
            // Finance; panelnya dipagari modeFinance di layar (izin saja tak memisahkan,
            // karena SuperAdmin memegang semuanya).
            // COST CENTER BARIS REALISASI MENGIKUTI UNIT KERJA PENGAJU, dan terkunci —
            // aturan yang sama dengan Registrasi Invoice Hutang, dari service yang sama.
            // Dibiarkan bebas dipilih, biaya bon bisa dibebankan ke departemen yang tidak
            // menanggungnya: realisasi dua departemen bergeser sekaligus — yang satu tampak
            // hemat, yang lain boros, dan keduanya salah.
            //
            // Karyawan dengan LEBIH DARI SATU departemen (Data Karyawan) boleh memilih di antara
            // departemennya sendiri — bukan bebas. Daftarnya milik KARYAWAN PEMILIK BON (bukan
            // akun yang membuka layar), bawaannya departemen yang dipilih saat bon diajukan.
            ...$this->ccRealisasi($request, $advance),
            'bisaUbahCc' => (bool) $request->user()?->can('ap.costcenter.override'),
            'canRealize' => (bool) $request->user()?->can('cashbook.advance.realize'),
            'canRefund' => (bool) $request->user()?->can('cashbook.advance.refund'),
            'canReimburse' => (bool) $request->user()?->can('cashbook.advance.reimburse'),
            /* ALOKASI KASBON — hanya bon Debit VA model baru (yang pembayarannya tidak berjurnal).
               Bon Debit VA lama sudah terlanjur berjurnal ke akun kartunya saat dibayar; membuka
               alokasi untuknya akan mendebit Uang Muka Operasional atas uang yang sudah tercatat
               keluar sekali. Pembedanya DATA (journal_id), bukan payment_method. */
            'allocations' => $this->allocationRows($advance),
            'allocationMode' => app(CashAdvanceService::class)->perluLanjutAlokasi($advance),
            /* Gerbang realisasi. Layar HANYA mencerminkan keadaan ini — yang benar-benar
               menahan ada di CashAdvanceService::realize(); menyembunyikan tombol saja tidak
               menghentikan POST-nya. */
            'lanjutAlokasi' => $this->keadaanLanjut($advance),
            // Mundur satu langkah / hapus pengajuan — izin cashbook.advance.undo (Hak Akses).
            'langkahMundur' => $request->user()?->can('cashbook.advance.undo')
                ? app(CashAdvanceService::class)->langkahMundur($advance) : null,
            // Edit Pengajuan — izin cashbook.advance.update, hanya selagi Diajukan (belum dibayar).
            'bisaEditPengajuan' => (bool) $request->user()?->can('cashbook.advance.update')
                && $advance->status === 'requested',
            // Edit Realisasi — izin cashbook.advance.realize-edit, hanya selama selisihnya belum diselesaikan.
            'bisaEditRealisasi' => (bool) $request->user()?->can('cashbook.advance.realize-edit')
                && app(CashAdvanceService::class)->bisaEditRealisasi($advance),
            'riwayatMundur' => \Illuminate\Support\Facades\DB::table('bs_cash_advance_mundur as m')
                ->leftJoin('users as u', 'u.id', '=', 'm.user_id')
                ->where('m.cash_advance_id', $advance->id)->orderByDesc('m.id')
                ->get(['m.langkah', 'm.alasan', 'm.created_at', 'u.name as oleh']),
        ]);
    }

    /**
     * Keadaan gerbang "Lanjut Realisasikan Bon" untuk satu bon.
     *
     * @return array{perlu:bool, sudah:bool, pada:?string}
     */
    private function keadaanLanjut(BsCashAdvance $advance): array
    {
        return [
            'perlu' => app(CashAdvanceService::class)->perluLanjutAlokasi($advance),
            'sudah' => $advance->allocation_done_at !== null,
            'pada'  => $advance->allocation_done_at?->toDateTimeString(),
        ];
    }

    // ── Sub-modul: Alokasi Bon Sementara ───────────────────────────────────────────────

    /** Daftar bon yang melewati langkah Alokasi Kasbon. */
    public function allocationIndex(Request $request): Response
    {
        abort_unless($request->user()?->can('cashbook.advance.view'), 403);

        /* Yang masuk daftar: bon Debit VA model baru (pembayarannya tidak berjurnal) yang
           sudah dibayar dan belum direalisasi — persis himpunan yang punya Alokasi Kasbon.
           Syaratnya diambil dari service supaya daftar ini tidak bisa berbeda dari gerbangnya.

           payment_method='debit_va' di bawah SECARA LOGIKA berlebihan hari ini: status
           'outstanding' berarti sudah dibayar, dan bon reguler yang dibayar SELALU berjurnal
           (journal_id terisi), jadi whereNull('journal_id') sudah menyingkirkannya. Ia ditulis
           tetap sebagai pernyataan maksud — kalau kelak ada cara pembayaran baru yang tidak
           berjurnal, tanpa baris ini ia akan diam-diam ikut masuk ke daftar alokasi. */
        // Dibatasi ke bon sendiri dengan aturan yang sama seperti daftar utama — menunya
        // memang hanya terbit untuk Finance, tetapi alamatnya bisa diketik langsung, dan
        // tanpa ini daftar alokasi menjadi jalan memutar menuju bon seluruh karyawan.
        $rows = $this->batasiKeMilikSendiri(BsCashAdvance::query(), $request)
            ->where('payment_method', 'debit_va')
            ->whereNull('journal_id')
            ->where('status', 'outstanding')
            ->with('cashAccount:id,code,name')
            ->latest('advance_date')->latest('id')
            ->paginate(50)->withQueryString();

        $alokasi = BsCashAdvanceAllocation::query()
            ->whereIn('cash_advance_id', $rows->getCollection()->pluck('id'))
            ->selectRaw('cash_advance_id, COUNT(*) AS jumlah, COALESCE(SUM(amount),0) AS total')
            ->groupBy('cash_advance_id')->get()->keyBy('cash_advance_id');

        $rows->setCollection($rows->getCollection()->map(function (BsCashAdvance $a) use ($alokasi) {
            $t = $alokasi[$a->id] ?? null;
            $total = round((float) ($t->total ?? 0), 2);

            return [
                'id'        => $a->id,
                'nomor'     => $a->advance_number,
                'tanggal'   => $a->advance_date?->toDateString(),
                'pemegang'  => $a->holder,
                'keperluan' => $a->purpose,
                'nominal'   => (float) $a->amount,
                'jumlahAlokasi' => (int) ($t->jumlah ?? 0),
                'dialokasikan'  => $total,
                'belum'     => round((float) $a->amount - $total, 2),
                'sudahLanjut' => $a->allocation_done_at !== null,
            ];
        }));

        return Inertia::render('BusinessSupport/CashBook/Advances/Allocation/Index', [
            'rows' => $rows,
        ]);
    }

    /** Layar Alokasi Kasbon satu bon — dipindah dari layar Realisasi Bon Sementara. */
    public function allocationShow(Request $request, BsCashAdvance $advance): Response
    {
        abort_unless($request->user()?->can('cashbook.advance.view'), 403);
        abort_unless($this->bolehBukaBon($request, $advance), 403);
        // Bon yang memang tidak punya langkah alokasi tidak boleh membuka layar ini: halaman
        // alokasi yang selalu kosong akan terbaca sebagai "bonnya belum dialokasikan".
        abort_unless(app(CashAdvanceService::class)->perluLanjutAlokasi($advance), 404);

        return Inertia::render('BusinessSupport/CashBook/Advances/Allocation/Show', [
            'advance' => $advance->load(['creditCard:id,name,card_number']),
            'cashAccounts' => $this->cashAccounts(),
            'allocations' => $this->allocationRows($advance),
            'lanjutAlokasi' => $this->keadaanLanjut($advance),
            'bisaUbah' => (bool) $request->user()?->can('cashbook.advance.realize'),
        ]);
    }

    /** Tombol "Lanjut Realisasikan Bon" — membuka gerbang realisasi untuk bon ini. */
    public function lanjutRealisasi(Request $request, BsCashAdvance $advance): RedirectResponse
    {
        abort_unless($request->user()?->can('cashbook.advance.realize'), 403);
        abort_unless(app(CashAdvanceService::class)->perluLanjutAlokasi($advance), 404);

        if ($advance->status !== 'outstanding') {
            return back()->withErrors(['lanjut' => 'Bon ini sudah tidak dalam tahap alokasi.']);
        }
        // Melanjutkan bon tanpa satu pun alokasi akan menabrak gerbang ADV_NO_ALLOCATION di
        // langkah berikutnya — ditolak di sini supaya alasannya terbaca di tempat kejadiannya.
        if ($this->allocationRows($advance)['total'] <= 0) {
            return back()->withErrors(['lanjut' => 'Belum ada Alokasi Kasbon. Uang Muka Operasional baru terbentuk dari alokasi, jadi bon ini belum bisa direalisasi.']);
        }

        if ($advance->allocation_done_at === null) {
            $advance->update([
                'allocation_done_at' => now(),
                'allocation_done_by' => $request->user()?->id,
            ]);
        }

        return redirect()->route('bs.advance.show', $advance->id)
            ->with('success', 'Bon '.$advance->advance_number.' dilanjutkan ke Realisasi Bon Sementara.');
    }

    /** @return array<string,mixed> */
    private function allocationRows(BsCashAdvance $advance): array
    {
        $rows = BsCashAdvanceAllocation::with('cashAccount:id,code,name')
            ->where('cash_advance_id', $advance->id)
            ->orderBy('alloc_date')->orderBy('id')->get()
            ->map(fn (BsCashAdvanceAllocation $a) => [
                'id' => $a->id,
                'alloc_date' => $a->alloc_date?->toDateString(),
                'description' => $a->description,
                'amount' => (float) $a->amount,
                'account_id' => $a->cash_account_id,
                'account' => trim((string) ($a->cashAccount?->code ? $a->cashAccount->code.' — ' : '').($a->cashAccount?->name ?? '')),
                'journal_id' => $a->journal_id,
            ])->values();

        $total = round((float) $rows->sum('amount'), 2);

        return [
            'rows' => $rows,
            'total' => $total,
            // Sisa yang masih boleh dialokasikan — sekaligus bagian bon yang tetap menjadi saldo
            // kartunya bila memang tidak jadi dipakai.
            'sisa' => round((float) $advance->amount - $total, 2),
        ];
    }

    public function realize(Request $request, BsCashAdvance $advance, CashAdvanceService $svc): RedirectResponse
    {
        abort_unless($request->user()?->can('cashbook.advance.realize'), 403);
        // Hanya bon yang boleh ia BUKA — layarnya memang sudah dijaga, tetapi realisasi
        // mencetak jurnal beban ke cost center pengaju: direalisasi orang lain, beban itu
        // menempel ke unit kerja yang salah dan tidak ada yang menyadarinya.
        abort_unless($this->bolehBukaBon($request, $advance), 403);

        $v = $request->validate([
            'realized_date' => ['required', 'date'],
            'defer_settlement' => ['nullable', 'boolean'],
            ...$this->aturanBarisRealisasi(),
        ], self::PESAN_BARIS_REALISASI);

        // Tanggal realisasi dikunci hari ini (UI read-only); penyelesaian sisa/kekurangan
        // selalu ditunda → ditangani dept Finance lewat Refund/Reimburse.
        $v['realized_date'] = now()->toDateString();
        $v['defer_settlement'] = true;
        if ($salah = app(CostCenterAkun::class)->diLuarDaftar($v['lines'], [], 'expense_account_id')) {
            return back()->with('error', 'Cost center di luar daftar COA untuk akun: '.implode(', ', $salah).'.');
        }

        try {
            $svc->realize($advance, $v, $request->user()->id);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('bs.advance.show', $advance)->with('success', 'Realisasi bon sementara tercatat — jurnal otomatis terbentuk.');
    }

    /**
     * Edit Realisasi — membetulkan rincian realisasi (mis. salah input nominal). Tanggal
     * realisasinya TETAP tanggal lama; jurnal lama dibuang dan jurnal baru diposting.
     */
    public function editRealisasi(Request $request, BsCashAdvance $advance, CashAdvanceService $svc): RedirectResponse
    {
        abort_unless($request->user()?->can('cashbook.advance.realize-edit'), 403);
        abort_unless($this->bolehBukaBon($request, $advance), 403);

        $v = $request->validate([
            ...$this->aturanBarisRealisasi(),
            'alasan' => ['required', 'string', 'max:255'],
        ], ['alasan.required' => 'Alasan edit wajib diisi.'] + self::PESAN_BARIS_REALISASI);
        // Baris realisasi lama yang sudah tercatat tetap boleh (forward-only).
        $lama = $advance->lines()->get(['expense_account_id', 'cost_center_id'])
            ->mapWithKeys(fn ($l) => [$l->expense_account_id.'|'.$l->cost_center_id => true])->all();
        if ($salah = app(CostCenterAkun::class)->diLuarDaftar($v['lines'], $lama, 'expense_account_id')) {
            return back()->with('error', 'Cost center di luar daftar COA untuk akun: '.implode(', ', $salah).'.');
        }

        try {
            $svc->editRealisasi($advance, $v['lines'], $v['alasan'], $request->user()->id);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('bs.advance.show', $advance)->with('success', 'Realisasi '.$advance->advance_number.' diperbarui — jurnal realisasi dibentuk ulang.');
    }

    private const PESAN_BARIS_REALISASI = [
        'lines.*.description.max' => 'Keterangan maksimal 500 karakter.',
    ];

    /** Aturan baris realisasi — dipakai bersama oleh Realisasi dan Edit Realisasi. */
    private function aturanBarisRealisasi(): array
    {
        return [
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.expense_account_id' => ['required', 'integer', 'exists:bs_accounts,id'],
            // WAJIB. Cost center inilah yang menentukan anggaran mana yang terpakai: ia
            // diteruskan ke bs_journal_lines.cost_center_id, lalu dibaca BudgetRealizationService
            // untuk mencocokkan realisasi dengan plafon per (akun, cost center). Baris realisasi
            // tanpa cost center tetap menjadi beban di GL tetapi TIDAK PERNAH muncul sebagai
            // realisasi di Budget Control — anggarannya seolah tidak pernah terpakai. Karena itu
            // di sini ia diwajibkan, berbeda dari BudgetController yang memang boleh
            // menganggarkan lintas cost center.
            'lines.*.cost_center_id' => ['required', 'integer', 'exists:bs_cost_centers,id'],
            'lines.*.description' => ['nullable', 'string', 'max:500'],
            'lines.*.amount' => ['required', 'numeric', 'min:0.01'],
        ];
    }

    public function refund(Request $request, BsCashAdvance $advance, CashAdvanceService $svc): RedirectResponse
    {
        // MILIK DEPT FINANCE. Dua izin, bukan satu: izin cashbook.advance.refund menentukan SIAPA yang
        // mengerjakan jenis penyelesaian ini, cashbook.advance.pay menandai bahwa ia memang
        // orang Finance. Tanpa yang kedua, pemindahan ini hanya tersembunyi di layar —
        // pengaju yang kebetulan diberi izin itu masih bisa menembusnya lewat POST langsung,
        // dan uang bank berpindah dari sisi yang salah tanpa Finance tahu.
        abort_unless($request->user()?->can('cashbook.advance.refund')
            && $request->user()?->can('cashbook.advance.pay'), 403);
        // #89: pilih bank penerima dana refund.
        $v = $request->validate([
            'settle_date' => ['required', 'date'],
            'cash_account_id' => ['nullable', 'integer', 'exists:bs_accounts,id'],
        ]);

        try {
            $svc->refund($advance, $v['settle_date'], $request->user()->id, $v['cash_account_id'] ?? null);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('bs.advance.finance.show', $advance)->with('success', 'Refund sisa panjar tercatat — jurnal otomatis terbentuk.');
    }

    /**
     * Masuk Saldo — sisa panjar bon Debit VA dikembalikan ke saldo kartunya, TANPA jurnal.
     *
     * Tanpa isian apa pun: nominalnya sudah pasti (sisa bon itu) dan kartunya sudah ditentukan
     * saat pembayaran. Meminta orang mengetik ulang keduanya hanya membuka peluang angka yang
     * berbeda dari sisanya yang sebenarnya.
     */
    public function masukSaldo(Request $request, BsCashAdvance $advance, CashAdvanceService $svc): RedirectResponse
    {
        // MILIK DEPT FINANCE. Dua izin, bukan satu: izin cashbook.advance.reimburse menentukan SIAPA yang
        // mengerjakan jenis penyelesaian ini, cashbook.advance.pay menandai bahwa ia memang
        // orang Finance. Tanpa yang kedua, pemindahan ini hanya tersembunyi di layar —
        // pengaju yang kebetulan diberi izin itu masih bisa menembusnya lewat POST langsung,
        // dan uang bank berpindah dari sisi yang salah tanpa Finance tahu.
        abort_unless($request->user()?->can('cashbook.advance.reimburse')
            && $request->user()?->can('cashbook.advance.pay'), 403);

        try {
            $svc->masukSaldo($advance, $request->user()->id);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('bs.advance.finance.show', $advance)
            ->with('success', 'Sisa panjar masuk ke saldo kartu — siap dipakai pengajuan berikutnya. Tidak ada jurnal tambahan: uangnya memang masih di akun kartu.');
    }

    public function reimburse(Request $request, BsCashAdvance $advance, CashAdvanceService $svc): RedirectResponse
    {
        // MILIK DEPT FINANCE. Dua izin, bukan satu: izin cashbook.advance.reimburse menentukan SIAPA yang
        // mengerjakan jenis penyelesaian ini, cashbook.advance.pay menandai bahwa ia memang
        // orang Finance. Tanpa yang kedua, pemindahan ini hanya tersembunyi di layar —
        // pengaju yang kebetulan diberi izin itu masih bisa menembusnya lewat POST langsung,
        // dan uang bank berpindah dari sisi yang salah tanpa Finance tahu.
        abort_unless($request->user()?->can('cashbook.advance.reimburse')
            && $request->user()?->can('cashbook.advance.pay'), 403);
        // #93: pilih bank sumber dana reimburse.
        $v = $request->validate([
            'settle_date' => ['required', 'date'],
            'cash_account_id' => ['nullable', 'integer', 'exists:bs_accounts,id'],
        ]);

        try {
            $svc->reimburse($advance, $v['settle_date'], $request->user()->id, $v['cash_account_id'] ?? null);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('bs.advance.finance.show', $advance)->with('success', 'Reimburse kekurangan panjar tercatat — jurnal otomatis terbentuk.');
    }

    /**
     * Cetak bon sementara (auto-print).
     *
     * PENGAJUAN memakai formulir eProperty yang sudah dipakai selama ini, dipilih menurut
     * nominalnya: di bawah Rp 1 juta → "KAS BON" satu halaman; mulai Rp 1 juta → "Bukti
     * Permohonan Pembayaran" + "Memo Internal". Ambangnya sama dengan yang dipakai rantai
     * persetujuan bon, sehingga formulir yang tercetak selalu sejalan dengan siapa yang
     * menandatanganinya.
     *
     * Bon yang SUDAH dibayar tetap memakai bukti pengeluaran lama — dokumen itu menerangkan
     * pengeluarannya, bukan pengajuannya.
     */
    public function print(Request $request, BsCashAdvance $advance): HttpResponse
    {
        abort_unless($request->user()?->can('cashbook.advance.print'), 403);
        abort_unless($this->bolehBukaBon($request, $advance), 403);
        // 'costCenter', BUKAN 'department': master Departemen sudah dicabut dan relasinya tidak
        // pernah ada di BsCashAdvance — memuatnya melempar RelationNotFoundException, dan
        // CETAK PENGAJUAN jadi 500 sejak itu. Unit kerja kini selalu dari cost center.
        $advance->load(['cashAccount:id,code,name', 'costCenter:id,code,name,manager']);

        $html = $advance->status === 'requested'
            ? app(PengajuanKasbonPrinter::class)->render($advance)
            : $this->renderPrint($advance, 'pengeluaran');

        return response($html)->header('Content-Type', 'text/html; charset=UTF-8');
    }

    /**
     * Tolak / batalkan pengajuan bon — Dept Finance, sebelum dibayar.
     *
     * Izinnya cashbook.advance.pay: yang berwenang menolak adalah pihak yang sama yang
     * berwenang membayar. Penolakan tanpa pembayaran bukan keputusan pengajunya sendiri.
     */
    public function cancel(Request $request, BsCashAdvance $advance, CashAdvanceService $svc): RedirectResponse
    {
        abort_unless($request->user()?->can('cashbook.advance.pay'), 403);

        $v = $request->validate(
            ['cancel_reason' => ['required', 'string', 'max:255']],
            ['cancel_reason.required' => 'Alasan penolakan wajib diisi.'],
        );

        try {
            $svc->cancel($advance, $v['cancel_reason'], $request->user()->id);
        } catch (BusinessRuleException $e) {
            return $this->balikDenganGalat($e, false);
        }

        return back()->with('success', 'Pengajuan '.$advance->advance_number.' dibatalkan.');
    }

    /**
     * Mundurkan bon SATU LANGKAH (Batal Refund/Reimburse → Batal Realisasi → Batal Pembayaran →
     * Hapus Pengajuan). Aturan & urutannya di CashAdvanceService::mundur().
     */
    public function mundur(Request $request, BsCashAdvance $advance, CashAdvanceService $svc): RedirectResponse
    {
        abort_unless($request->user()?->can('cashbook.advance.undo'), 403);
        abort_unless($this->bolehBukaBon($request, $advance), 403);

        $v = $request->validate([
            'langkah' => ['required', 'string', 'in:settle,card,realize,pay,delete'],
            'alasan' => ['required', 'string', 'max:255'],
        ], ['alasan.required' => 'Alasan wajib diisi.']);

        try {
            $label = $svc->mundur($advance, $v['langkah'], $v['alasan'], $request->user()->id);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        if ($v['langkah'] === 'delete') {
            return redirect()->route('bs.advance.index')->with('success', 'Pengajuan '.$advance->advance_number.' dihapus.');
        }

        return back()->with('success', $label.' '.$advance->advance_number.' berhasil — bon mundur satu langkah.');
    }

    /** Riwayat bon yang DITOLAK sebelum dibayar. Baca-saja — tidak ada jurnal yang menyertainya. */
    public function cancelledIndex(Request $request): Response
    {
        abort_unless($request->user()?->can('cashbook.advance.pay'), 403);

        $rows = BsCashAdvance::query()
            ->whereNotNull('cancelled_at')
            ->when($request->input('search'), fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('holder', 'like', "%{$s}%")
                ->orWhere('purpose', 'like', "%{$s}%")
                ->orWhere('advance_number', 'like', "%{$s}%")
                ->orWhere('cancel_reason', 'like', "%{$s}%")))
            ->latest('cancelled_at')->paginate(50)->withQueryString();

        $nama = DB::table('users')->whereIn('id', collect($rows->items())->pluck('cancelled_by')->filter()->unique())
            ->pluck('name', 'id');

        $rows->through(fn (BsCashAdvance $a) => [
            'id' => $a->id,
            'advance_number' => $a->advance_number,
            'advance_date' => optional($a->advance_date)->toDateString(),
            'holder' => $a->holder,
            'purpose' => $a->purpose,
            'amount' => (float) $a->amount,
            'cancelled_at' => optional($a->cancelled_at)->translatedFormat('d M Y H:i'),
            'cancelled_by' => $nama[$a->cancelled_by] ?? null,
            'cancel_reason' => $a->cancel_reason,
        ]);

        return Inertia::render('BusinessSupport/CashBook/Advances/Cancelled', [
            'rows' => $rows,
            'filters' => $request->only(['search']),
            'total' => (float) BsCashAdvance::whereNotNull('cancelled_at')->sum('amount'),
        ]);
    }

    /** Cetak bukti REALISASI bon sementara (auto-print) — format eProperty (logo + jurnal rincian + ttd). */
    public function printRealisasi(Request $request, BsCashAdvance $advance): HttpResponse
    {
        abort_unless($request->user()?->can('cashbook.advance.print-realisasi'), 403);
        abort_unless($this->bolehBukaBon($request, $advance), 403);
        abort_unless($advance->status === 'realized', 404, 'Bon belum direalisasi.');
        $advance->load(['cashAccount:id,code,name', 'costCenter:id,code,name', 'lines.expenseAccount:id,code,name']);
        $this->buangBarisPengajuan($advance);

        return response($this->renderRealisasiPrint($advance))->header('Content-Type', 'text/html; charset=UTF-8');
    }

    /**
     * Rincian realisasi bon MIGRASI eProperty ikut memuat baris akun Uang Muka Operasional
     * (lawan jurnalnya di eProperty). Baris itu sudah diwakili baris "Pengajuan" di cetakan &
     * layar, jadi ditampilkan dua kali. Disembunyikan dari TAMPILAN saja — datanya tidak diubah.
     * Bon yang dibuat di aplikasi tidak pernah punya baris semacam itu.
     */
    private function buangBarisPengajuan(BsCashAdvance $advance): void
    {
        if (! $advance->advance_account_id || ! $advance->relationLoaded('lines')) {
            return;
        }
        $advance->setRelation('lines', $advance->lines
            ->reject(fn ($l) => (int) $l->expense_account_id === (int) $advance->advance_account_id)
            ->values());
    }

    /**
     * Cetak REALISASI BON SEMENTARA — layout mengikuti lampiran eProperty:
     * logo + judul, Tanggal/Departemen, Dibayarkan Kepada, Dibayar Via / Cara Pembayaran,
     * tabel JURNAL rincian (Pengajuan = Uang Muka Operasional, Realisasi = akun beban) +
     * No. Voucher + Total (selisih/settlement), Terbilang, dan blok tanda tangan berjenjang.
     */
    private function renderRealisasiPrint(BsCashAdvance $advance): string
    {
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        // Jumlah format Indonesia "1.298.000,00" (ribuan titik, 2 desimal, tanpa "Rp") — seragam
        // dengan cetakan Bon Sementara lainnya.
        $amt = fn ($n) => number_format((float) $n, 2, ',', '.');

        // ── Cara pembayaran: parse nama akun kas/bank "{BANK} REK. {NO}" → bank + rekening; deteksi KAS. ──
        $cashName = (string) ($advance->cashAccount?->name ?? '');
        $isKas = $cashName !== '' && stripos($cashName, 'rek') === false && stripos($cashName, 'kas') !== false;
        $bankName = $cashName;
        $rekening = '';
        if (preg_match('/^(.*?)\s*REK\.?\s*(.+)$/i', $cashName, $m)) {
            $bankName = trim($m[1]);
            $rekening = trim($m[2]);
        } elseif ($isKas) {
            $bankName = '';
        }
        // Kotak centang (■ = dipilih). Bon dibayar Finance via transfer bank kecuali sumber = KAS.
        $bx = fn (bool $on) => '<span class="bx">'.($on ? '&#10003;' : '&nbsp;&nbsp;').'</span>';

        // ── No. Voucher = nomor REFERENSI JURNAL realisasi (format JKB/yymm/seq — konsisten dgn referensi
        //    jurnal di modul Account Payable). Fallback ke nomor bon bila jurnal belum ada. ──
        $voucher = BsJournal::whereKey($advance->realize_journal_id)->value('journal_number') ?: $advance->advance_number;

        // ── Baris jurnal: Pengajuan (Dr Uang Muka Operasional) + Realisasi (Dr akun beban per baris). ──
        $advAccount = BsAccount::find($advance->advance_account_id);
        $pengajuanCode = $advAccount?->code ?? (string) config('business_support.gl_accounts.advance_op');
        /* DUA BENTUK TABEL.
           - Bon Debit VA (model baru, ber-Alokasi Kasbon): 4 kolom. Nominal bon di kolom Pengajuan
             TANPA no. akun; akun Uang Muka Operasional menempel di baris "Dialokasikan" (total
             Alokasi Kasbon — sama dengan kotak "Sudah Dialokasikan"), lalu baris realisasi di Jumlah.
           - Bon lainnya: 3 kolom seperti semula — akun & nominal bon di baris pertama kolom Jumlah.
           Total di bawah tetap selisih realisasi (settlement) pada keduanya. */
        // Baris REALISASI (biaya terpakai) dicetak dalam kurung = pengurang uang muka, supaya tabel
        // terbaca Pengajuan − Realisasi = Total (permintaan ACC 24 Okt 2026).
        $kurang = fn ($n) => '('.$amt($n).')';
        $debitVa = app(CashAdvanceService::class)->perluLanjutAlokasi($advance);
        if ($debitVa) {
            $alokasi = app(CashAdvanceAllocationService::class)->totalAlokasi($advance);
            $rows = '<tr><td class="ac"></td><td>'.$e($advance->purpose).'</td><td class="r">'.$amt($advance->amount).'</td><td class="r"></td></tr>'
                .'<tr><td class="ac">'.$e($pengajuanCode).'</td><td>Dialokasikan</td><td class="r"></td><td class="r">'.$amt($alokasi).'</td></tr>';
            foreach ($advance->lines as $l) {
                $rows .= '<tr><td class="ac">'.$e($l->expenseAccount?->code).'</td><td>'.$e($l->description).'</td><td class="r"></td><td class="r">'.$kurang($l->amount).'</td></tr>';
            }
        } else {
            $rows = '<tr><td class="ac">'.$e($pengajuanCode).'</td><td>'.$e($advance->purpose).'</td><td class="r">'.$amt($advance->amount).'</td></tr>';
            foreach ($advance->lines as $l) {
                $rows .= '<tr><td class="ac">'.$e($l->expenseAccount?->code).'</td><td>'.$e($l->description).'</td><td class="r">'.$kurang($l->amount).'</td></tr>';
            }
        }

        $settlement = (float) $advance->settlement;       // + = sisa dikembalikan (refund) / − = kekurangan (reimburse)
        $total = abs($settlement);
        // Reimburse hanya bila realisasi MELEBIHI pengajuan. Refund dan Match memakai formulir
        // yang sama — pada Match selisihnya nol, dan formulir refund-lah yang dipakai.
        $isReimburse = $settlement < 0;
        $terbilang = strtoupper(trim(preg_replace('/\s+/', ' ', terbilang((int) round($total)))).' rupiah');
        $tgl = optional($advance->realized_date)->format('d-m-Y') ?: '-';
        // Cost center bon, BUKAN `department`: master Departemen sudah dicabut dan relasinya tidak
        // ada di BsCashAdvance, sehingga baris ini dulu SELALU tercetak "-".
        $dept = $advance->costCenter?->name ?: '-';
        $holder = (string) $advance->holder;
        $printDate = now()->format('d-m-Y H.i.s');
        $logo = '/images/mup-logo.jpeg';

        $css = '@page{size:A4;margin:12mm}*{box-sizing:border-box}'
            .'body{font-family:Arial,"Segoe UI",sans-serif;font-size:12px;color:#111;margin:0}'
            .'.cb{text-align:right;font-style:italic;font-size:11px;margin-bottom:2px}'
            .'.hd{width:100%;border:0;border-collapse:collapse}.hd td{vertical-align:top;border:0}'
            .'.logo{width:62px;height:62px;object-fit:contain}'
            .'.title{font-size:20px;font-weight:bold;text-decoration:underline;letter-spacing:.5px}'
            .'.meta{width:100%;border:0;border-collapse:collapse;margin-top:6px;font-size:12px}.meta td{border:0;padding:1px 0;vertical-align:top}'
            .'hr.sep{border:0;border-top:1.5px solid #555;margin:8px 0 8px}'
            .'.pay{width:100%;border:0;border-collapse:collapse;margin:4px 0 8px;font-size:12px}.pay td{border:0;vertical-align:top;padding:1px 0}'
            .'.bx{display:inline-block;width:13px;height:13px;border:1px solid #333;text-align:center;line-height:12px;font-size:11px;margin-right:6px;vertical-align:middle}'
            .'table.grid{width:100%;border-collapse:collapse;margin-top:2px;font-size:12px}'
            .'table.grid th,table.grid td{border:1px solid #333;padding:4px 7px;vertical-align:top}'
            .'table.grid th{background:#fff;font-weight:bold;text-align:center}'
            .'table.grid td.ac{white-space:nowrap;width:110px}table.grid td.r,table.grid th.r{text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums;width:120px}'
            .'.vc{font-weight:bold}.tlbl{float:right;font-weight:bold}'
            .'.terbilang{margin:7px 0 12px}'
            // Tanda tangan: lebar kolom SAMA (table-layout fixed + colgroup) dan garis kotak penuh,
            // seperti BPP. Dulu lebarnya mengikuti panjang teks ("Direksi" sempit, nama PT
            // melebar) dan garisnya hanya sisi kiri sel, sehingga sekat di bawah judul gabungan
            // baru mulai di tengah.
            .'table.sign{width:100%;border-collapse:collapse;font-size:11px;table-layout:fixed}'
            .'table.sign td{border:1px solid #333;padding:4px 6px;text-align:center;word-wrap:break-word}'
            .'tr.sl td{vertical-align:middle;height:14px}tr.ss td{height:70px}tr.sr td{vertical-align:middle;font-weight:600}';

        $body = '<div class="cb">Created By Java Core System ('.$e($printDate).')</div>'
            .'<table class="hd"><tr>'
            .'<td style="width:72px"><img class="logo" src="'.$logo.'" alt="logo"></td>'
            .'<td style="text-align:center"><span class="title">REALISASI BON SEMENTARA</span>'
            .'<table class="meta"><tr><td style="width:50%;text-align:left">Tanggal&nbsp;&nbsp;&nbsp;&nbsp;: '.$e($tgl).'</td>'
            .'<td style="width:50%;text-align:left">Departemen&nbsp;&nbsp;: '.$e($dept).'</td></tr></table></td>'
            .'<td style="width:72px"></td></tr></table>'
            .'<hr class="sep">'
            .'<div style="margin-bottom:4px">Dibayarkan Kepada&nbsp;&nbsp;&nbsp;:&nbsp; <b>'.$e($holder).'</b></div>'
            .'<table class="pay"><tr>'
            .'<td style="width:55%">Dibayar Via :<br>'
            .$bx($isKas).'KAS<br>'
            .$bx(! $isKas).'Bank ( '.$e($bankName).' )<br>'
            .'<span style="margin-left:1px">No. Rekening : '.$e($rekening).'</span></td>'
            .'<td style="width:45%">Cara Pembayaran Bank :<br>'
            .$bx(! $isKas).'Transfer / Check<br>'
            .$bx($isKas).'Tunai</td>'
            .'</tr></table>'
            .'<table class="grid"><thead><tr><th>No.Account</th><th>Uraian Pembayaran</th>'.($debitVa ? '<th class="r">Pengajuan</th>' : '').'<th class="r">Jumlah</th></tr></thead>'
            .'<tbody>'.$rows.'</tbody>'
            // Reimburse = perusahaan MASIH berhutang ke pemegang bon, jadi totalnya ditulis dalam
            // kurung persis seperti lampirannya — tanda baca itulah yang membedakan "diterima kembali"
            // dari "harus dibayarkan", dan artinya hilang begitu kurungnya dibuang.
            .'<tfoot><tr><td class="vc">No. Voucher'.($isReimburse ? '' : ' : -').'</td>'
            .'<td'.($debitVa ? ' colspan="2"' : '').'><span class="vc">'.$e($voucher).'</span><span class="tlbl">Total</span></td>'
            .'<td class="r"><b>'.($isReimburse ? '(Rp. '.$amt($total).')' : 'Rp. '.$amt($total)).'</b></td></tr></tfoot></table>'
            .'<div>No. Cek / BG : -</div>'
            .'<div class="terbilang">Terbilang : '.$e($terbilang).'</div>'
            .$this->blokTtdRealisasi($isReimburse, $holder);

        return '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><title>'.$e($advance->advance_number).' — Realisasi</title>'
            .'<style>'.$css.'</style></head><body>'.$body
            .'<button class="noprint" onclick="window.print()" style="margin-top:14px;padding:6px 14px">Cetak</button>'
            .'<style>@media print{.noprint{display:none}}</style>'
            .'<script>window.onload=function(){setTimeout(function(){window.print()},350)}</script></body></html>';
    }

    private function renderPrint(BsCashAdvance $advance, string $mode): string
    {
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $rp = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.');
        $co = (string) config('business_support.company.name', 'PT. Mitra Usaha Propertindo');
        $now = now()->translatedFormat('d F Y H:i');
        $isReal = $mode === 'realisasi';
        $isReq = ! $isReal && $advance->status === 'requested'; // #61: cetak bukti pengajuan
        $title = $isReal ? 'BUKTI REALISASI BON SEMENTARA' : ($isReq ? 'BUKTI PENGAJUAN BON SEMENTARA' : 'BUKTI PENGELUARAN BON SEMENTARA');

        $rows = '';
        if ($isReal) {
            foreach ($advance->lines as $l) {
                $rows .= '<tr><td>'.$e($l->expenseAccount?->code).' '.$e($l->expenseAccount?->name).'</td><td>'.$e($l->description).'</td><td class="r">'.$rp($l->amount).'</td></tr>';
            }
            $rows .= '<tr class="tot"><td colspan="2" class="r">Total Realisasi</td><td class="r">'.$rp($advance->realized_amount).'</td></tr>';
            $settle = (float) $advance->settlement;
            $rows .= '<tr><td colspan="2" class="r">Nominal Bon</td><td class="r">'.$rp($advance->amount).'</td></tr>';
            $rows .= '<tr><td colspan="2" class="r">'.($settle >= 0 ? 'Sisa dikembalikan ke kas (Refund)' : 'Kekurangan (Reimburse)').'</td><td class="r">'.$rp(abs($settle)).'</td></tr>';
        }

        $body = $isReal
            ? '<table><thead><tr><th>Akun Beban</th><th>Keterangan</th><th class="r">Jumlah</th></tr></thead><tbody>'.$rows.'</tbody></table>'
            : '<table><tr><th>Nominal</th><td class="r">'.$rp($advance->amount).'</td></tr><tr><th>'.($isReq ? 'Status' : 'Dari Kas/Bank').'</th><td>'.($isReq ? 'Menunggu pembayaran Dept Finance' : $e($advance->cashAccount?->code).' '.$e($advance->cashAccount?->name)).'</td></tr></table>';

        return '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><title>'.$e($advance->advance_number).'</title><style>
            @page{size:A5 landscape;margin:12mm}*{box-sizing:border-box}body{font-family:"Segoe UI",Arial,sans-serif;font-size:12px;color:#1f2937;margin:0}
            h1{font-size:15px;margin:0 0 2px;color:#b45309}h2{font-size:12px;margin:0;color:#92400e}
            .head{border-bottom:2px solid #b45309;padding-bottom:6px;margin-bottom:10px;display:flex;justify-content:space-between}
            .muted{color:#6b7280;font-size:10px}table{border-collapse:collapse;width:100%;margin:6px 0;font-size:11px}
            th,td{border:1px solid #cbd5e1;padding:5px 8px;text-align:left}.r{text-align:right;font-variant-numeric:tabular-nums}
            .tot{font-weight:bold;background:#fef3c7}.sign{margin-top:28px;display:flex;justify-content:space-between;font-size:11px;text-align:center}
            dl{display:grid;grid-template-columns:120px 1fr;gap:2px 8px;margin:6px 0}dt{color:#6b7280}
            @media print{.noprint{display:none}}</style></head><body>
            <div class="head"><div><h1>'.$e($title).'</h1><div class="muted">'.$e($co).'</div></div>
            <div class="muted" style="text-align:right">No: '.$e($advance->advance_number).'<br>Cetak: '.$e($now).'</div></div>
            <dl><dt>Tanggal</dt><dd>'.$e(($isReal ? $advance->realized_date : $advance->advance_date)?->translatedFormat('d F Y')).'</dd>
            <dt>Pemegang Bon</dt><dd>'.$e($advance->holder).'</dd>
            <dt>Keperluan</dt><dd>'.$e($advance->purpose).'</dd></dl>'
            .$body.
            '<div class="sign"><div>Dibuat,<br><br><br>(__________)</div><div>Mengetahui,<br><br><br>(__________)</div><div>Finance,<br><br><br>(__________)</div></div>
            <button class="noprint" onclick="window.print()" style="margin-top:10px;padding:6px 14px">Cetak</button>
            <script>window.onload=function(){setTimeout(function(){window.print()},300)}</script></body></html>';
    }

    private function cashAccounts()
    {
        return BsAccount::where('is_active', true)->where('is_postable', true)
            ->where('code', 'like', '101010%')->orderBy('code')->get(['id', 'code', 'name']);
    }

    /** #150 — daftar departemen (HR) untuk filter laporan & auto-isi form. */
    private function departments()
    {
        return BsCostCenter::where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']);
    }

    /**
     * Blok tanda tangan cetakan Realisasi Bon Sementara.
     *
     * Kedua formulir lampiran berbeda justru di sini, dan bedanya bermakna:
     *   · REFUND / MATCH — empat judul, kolom terakhir ditandatangani PERUSAHAAN, sebab uangnya
     *     kembali MASUK ke kas dan perusahaanlah yang menerima;
     *   · REIMBURSE — lima judul (bertambah "Di Bayarkan Oleh,"), dan dua kolom terakhir menjadi
     *     Finance yang membayarkan serta pemegang bon yang menerima uangnya.
     */
    private function blokTtdRealisasi(bool $isReimburse, string $holder): string
    {
        $e = fn ($x) => htmlspecialchars((string) $x, ENT_QUOTES, 'UTF-8');
        $co = (string) config('business_support.company.name', 'PT. Mitra Usaha Propertindo');

        // Di Verifikasi = Manager Accounting + Manager Finance; Di Setujui = General Manager +
        // Direksi — persis formulir eProperty (dulu Manager Finance terkelompok di "Di Setujui").
        $judul = $isReimburse
            ? [['Diajukan Oleh,', 1], ['Di Verifikasi Oleh,', 2], ['Di Setujui Oleh,', 2], ['Di Bayarkan Oleh,', 1], ['Di Terima Oleh,', 1]]
            : [['Diajukan Oleh,', 1], ['Di Verifikasi Oleh,', 2], ['Di Setujui Oleh,', 2], ['Di Terima Oleh,', 1]];
        $peran = $isReimburse
            ? ['SPV/Manager Dept.', 'Manager Accounting', 'Manager Finance', 'General Manager', 'Direksi', 'Finance', $holder]
            : ['SPV/Manager Dept.', 'Manager Accounting', 'Manager Finance', 'General Manager', 'Direksi', $co];

        $barisJudul = '';
        foreach ($judul as [$teks, $span]) {
            $barisJudul .= '<td colspan="'.$span.'">'.$e($teks).'</td>';
        }
        $barisKosong = str_repeat('<td></td>', count($peran));
        $barisPeran = '';
        foreach ($peran as $p) {
            $barisPeran .= '<td>'.$e($p).'</td>';
        }

        $lebar = round(100 / count($peran), 4);

        return '<table class="sign"><colgroup>'.str_repeat('<col style="width:'.$lebar.'%">', count($peran)).'</colgroup>'
            .'<tr class="sl">'.$barisJudul.'</tr>'
            .'<tr class="ss">'.$barisKosong.'</tr>'
            .'<tr class="sr">'.$barisPeran.'</tr>'
            .'</table>';
    }
}
