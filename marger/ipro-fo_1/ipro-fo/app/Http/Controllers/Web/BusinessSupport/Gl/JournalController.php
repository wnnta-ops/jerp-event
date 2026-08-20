<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Gl;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsBudget;
use App\Domain\BusinessSupport\Models\BsCostCenter;
use App\Domain\BusinessSupport\Models\BsFiscalPeriod;
use App\Domain\BusinessSupport\Models\BsJournal;
use App\Domain\BusinessSupport\Models\BsJournalLine;
use App\Domain\BusinessSupport\Services\BudgetRealizationService;
use App\Domain\BusinessSupport\Services\JournalPartyResolver;
use App\Domain\BusinessSupport\Services\JournalService;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class JournalController extends Controller
{
    /** Keterangan anggaran per jurnal — nilainya dipakai bersama layar & saringannya. */
    public const BUDGET_STATUSES = [
        'over'  => 'Over Budget',
        'under' => 'Under Budget',
        'none'  => 'Tanpa Anggaran',
    ];

    /**
     * Tentukan keterangan anggaran tiap jurnal pada satu halaman.
     *
     * Realisasi dihitung dengan BudgetRealizationService — perkakas yang sama dengan halaman
     * Budget Control dan gerbang over-budget — supaya angka yang dirujuk keterangan ini persis
     * angka yang terbaca di Master Budget.
     *
     * Hanya (akun + cost center) yang benar-benar punya anggaran disetujui yang ditanya
     * realisasinya, jadi jumlah kueri mengikuti banyaknya anggaran yang tersentuh halaman ini,
     * bukan banyaknya baris jurnalnya.
     *
     * @param  \Illuminate\Support\Collection<int, \App\Domain\BusinessSupport\Models\BsJournal>  $jurnal
     * @return array{status: array<int,string>, catatan: array<int,?string>}
     */
    private function statusAnggaran($jurnal, BudgetRealizationService $realisasi): array
    {
        // Pasangan (tahun, akun, cost center efektif) yang disentuh halaman ini.
        $pasangan = [];
        foreach ($jurnal as $j) {
            foreach ($j->lines as $l) {
                $cc = $l->cost_center_id ?: $j->cost_center_id;
                $pasangan[((int) $j->period_year).'|'.((int) $l->account_id).'|'.((int) ($cc ?: 0))] = true;
            }
        }
        if ($pasangan === []) {
            return ['status' => [], 'catatan' => []];
        }

        $tahun = [];
        $akun = [];
        foreach (array_keys($pasangan) as $k) {
            [$t, $a] = explode('|', $k);
            $tahun[(int) $t] = true;
            $akun[(int) $a] = true;
        }

        // Anggaran disetujui yang menyangkut akun-akun itu — termasuk anggaran lintas cost
        // center (cost_center_id kosong), yang berlaku untuk cost center mana pun.
        $anggaran = BsBudget::whereIn('year', array_keys($tahun))
            ->whereIn('account_id', array_keys($akun))
            ->where('status', 'approved')
            ->get(['id', 'year', 'account_id', 'cost_center_id', 'amount']);
        if ($anggaran->isEmpty()) {
            return ['status' => [], 'catatan' => []];
        }

        $namaAkun = BsAccount::whereIn('id', $anggaran->pluck('account_id')->unique())
            ->pluck('name', 'id');
        $rp = fn ($v) => 'Rp '.number_format((float) $v, 0, ',', '.');

        // Realisasi dihitung SEKALI per anggaran, lalu dipakai ulang oleh semua jurnal yang
        // menyentuhnya.
        $dipakai = [];
        foreach ($anggaran as $b) {
            $cc = $b->cost_center_id ? (int) $b->cost_center_id : null;
            $pakai = round($realisasi->total((int) $b->year, (int) $b->account_id, $cc), 2);
            $plafon = round((float) $b->amount, 2);
            $dipakai[$b->id] = [
                'year' => (int) $b->year, 'account_id' => (int) $b->account_id, 'cc' => $cc,
                // Over = realisasi sudah MELEBIHI anggaran. Under = realisasi masih lebih
                // kecil daripada anggaran. Keduanya sama-sama BERANGGARAN — itu yang
                // membedakannya dari "Tanpa Anggaran", yang memang tidak punya anggaran
                // sama sekali sehingga tidak ada yang bisa dilampaui maupun disisakan.
                'over'  => $pakai > $plafon + 0.001,
                'sisa'  => round($plafon - $pakai, 2),
                'teks'  => ($namaAkun[$b->account_id] ?? ('Akun #'.$b->account_id))
                    .': anggaran '.$rp($plafon).', realisasi '.$rp($pakai)
                    .' → sisa '.$rp($plafon - $pakai),
            ];
        }

        $status = [];
        $catatan = [];
        foreach ($jurnal as $j) {
            $adaLewat = false;
            $pesanLewat = [];
            $palingSempit = null;   // anggaran dengan sisa terkecil di antara yang belum lewat

            foreach ($j->lines as $l) {
                $cc = (int) ($l->cost_center_id ?: $j->cost_center_id ?: 0);
                foreach ($dipakai as $b) {
                    $cocok = $b['year'] === (int) $j->period_year
                        && $b['account_id'] === (int) $l->account_id
                        // Anggaran lintas cost center berlaku untuk cost center mana pun.
                        && ($b['cc'] === null || $b['cc'] === $cc);
                    if (! $cocok) {
                        continue;
                    }
                    if ($b['over']) {
                        $adaLewat = true;
                        $pesanLewat[$b['teks']] = true;
                    } elseif ($palingSempit === null || $b['sisa'] < $palingSempit['sisa']) {
                        $palingSempit = $b;
                    }
                }
            }

            // Satu baris yang melewati anggaran sudah cukup membuat jurnalnya over: itulah
            // baris yang akan ditolak gerbangnya bila jurnal serupa diinput lagi.
            if ($adaLewat) {
                $status[$j->id] = 'over';
                $catatan[$j->id] = implode(' · ', array_keys($pesanLewat));

                continue;
            }

            // Under Budget juga MEMBAWA ANGKANYA. Tanpa itu, "Under Budget" dan "Tanpa
            // Anggaran" hanya berbeda warna, dan orang tidak punya cara melihat anggaran
            // mana yang sedang dipatuhi — padahal justru itu bedanya. Bila satu jurnal
            // menyentuh beberapa anggaran, yang disebut adalah yang sisanya PALING SEDIKIT:
            // anggaran itulah yang paling dulu menolak input berikutnya.
            $status[$j->id] = $palingSempit !== null ? 'under' : 'none';
            $catatan[$j->id] = $palingSempit['teks'] ?? null;
        }

        return ['status' => $status, 'catatan' => $catatan];
    }

    /**
     * Saring daftar jurnal berdasarkan keterangan anggarannya.
     *
     * Keterangan itu hasil hitungan, bukan kolom, jadi ia tidak bisa langsung di-WHERE.
     * Yang disaring adalah PASANGAN (tahun + akun + cost center) yang anggarannya sudah
     * terlampaui: sebuah jurnal ikut "Over Budget" bila salah satu barisnya menyentuh
     * pasangan itu — persis baris yang akan ditolak gerbang over-budget.
     *
     * Hanya dijalankan saat saringannya dipakai, karena ia menghitung realisasi seluruh
     * anggaran disetujui pada rentang tahun yang tampil.
     */
    private function saringAnggaran($q, string $status, $from, $to)
    {
        $realisasi = app(BudgetRealizationService::class);
        $anggaran = BsBudget::whereBetween('year', [(int) $from->year, (int) $to->year])
            ->where('status', 'approved')
            ->get(['id', 'year', 'account_id', 'cost_center_id', 'amount']);

        $semua = [];
        $lewat = [];
        foreach ($anggaran as $b) {
            $cc = $b->cost_center_id ? (int) $b->cost_center_id : null;
            $p = ['year' => (int) $b->year, 'account_id' => (int) $b->account_id, 'cc' => $cc];
            $semua[] = $p;
            if (round($realisasi->total((int) $b->year, (int) $b->account_id, $cc), 2) > round((float) $b->amount, 2) + 0.001) {
                $lewat[] = $p;
            }
        }

        // Cocokkan satu baris jurnal dengan salah satu pasangan. Cost center efektif dibaca
        // sama dengan BudgetRealizationService: baris dulu, baru header.
        $cocok = function ($pasangan) {
            return function ($l) use ($pasangan) {
                $l->where(function ($w) use ($pasangan) {
                    foreach ($pasangan as $p) {
                        $w->orWhere(function ($x) use ($p) {
                            $x->whereRaw('bs_journals.period_year = ?', [$p['year']])
                                ->where('bs_journal_lines.account_id', $p['account_id']);
                            if ($p['cc'] !== null) {
                                $x->whereRaw('COALESCE(bs_journal_lines.cost_center_id, bs_journals.cost_center_id) = ?', [$p['cc']]);
                            }
                        });
                    }
                });
            };
        };

        return match ($status) {
            // Tidak ada anggaran yang terlampaui → tidak ada jurnal yang bisa disebut over.
            'over'  => $lewat === [] ? $q->whereRaw('1 = 0') : $q->whereHas('lines', $cocok($lewat)),
            'under' => $semua === [] ? $q->whereRaw('1 = 0')
                : $q->whereHas('lines', $cocok($semua))
                    ->when($lewat !== [], fn ($x) => $x->whereDoesntHave('lines', $cocok($lewat))),
            'none'  => $semua === [] ? $q : $q->whereDoesntHave('lines', $cocok($semua)),
            default => $q,
        };
    }

    public function index(Request $request): Response
    {
        abort_unless($request->user()?->canModule('gl'), 403);

        // Filter periode tanggal — default bulan berjalan; mencakup SEMUA jenis sumber jurnal.
        $from = $request->date('date_from') ?? now()->startOfMonth();
        $to   = $request->date('date_to')   ?? now()->endOfMonth();

        // ── Filter per kolom ────────────────────────────────────────────────────────
        //
        // Tiap kolom di layar punya saringannya sendiri, termasuk kolom rincian (Akun &
        // Keterangan Baris). Semuanya dikumpulkan di SATU tempat dan dipakai ulang oleh
        // query daftar maupun rekap, supaya angka rekap tidak pernah menghitung baris yang
        // tidak ditampilkan.
        //
        // Status SENGAJA tidak ikut di sini: rekap memang harus menyebutkan jumlah SETIAP
        // status pada rentang ini, jadi menyaringnya lebih dulu akan membuat tiga dari empat
        // angkanya selalu nol.
        $saring = function ($q) use ($request, $from, $to) {
            return $q
                ->when($request->input('source'), fn ($x, $v) => $x->where('source', $v))
                ->when($request->input('f_number'), fn ($x, $v) => $x->where('journal_number', $v))
                ->when($request->input('f_date'), fn ($x, $v) => $x->whereDate('journal_date', $v))
                ->when($request->input('f_desc'), fn ($x, $v) => $x->where('description', $v))
                ->when($request->input('f_lines'), fn ($x, $v) => $x->has('lines', '=', (int) $v))
                ->when($request->filled('f_debit'), fn ($x) => $x->where('total_debit', (float) $request->input('f_debit')))
                ->when($request->filled('f_credit'), fn ($x) => $x->where('total_credit', (float) $request->input('f_credit')))
                // Saringan rincian menjaring JURNALNYA, bukan barisnya: sebuah jurnal ikut
                // tampil bila salah satu barisnya cocok, dan seluruh barisnya tetap
                // ditampilkan supaya debit & kreditnya tetap seimbang di layar.
                ->when($request->input('f_account'), fn ($x, $v) => $x->whereHas('lines', fn ($l) => $l->where('account_id', (int) $v)))
                ->when($request->input('f_line_desc'), fn ($x, $v) => $x->whereHas('lines', fn ($l) => $l->where('description', $v)))
                ->when($request->input('f_budget'), fn ($x, $v) => $this->saringAnggaran($x, (string) $v, $from, $to))
                ->when($request->input('search'), fn ($x, $s) => $x->where(fn ($w) => $w->where('journal_number', 'like', "%{$s}%")->orWhere('description', 'like', "%{$s}%")->orWhere('reference', 'like', "%{$s}%")));
        };

        $rows = $saring(BsJournal::query()
            ->with(['lines.account:id,code,name', 'costCenter:id,code,name'])
            ->withCount('lines')
            ->whereBetween('journal_date', [$from->toDateString(), $to->toDateString()]))
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->latest('journal_date')->latest('id')
            ->paginate(50)->withQueryString();

        // ── Periode yang sudah ditutup ──────────────────────────────────────────────
        //
        // Jurnal pada periode CLOSED tidak boleh diubah sama sekali — JournalService sudah
        // menolaknya lewat assertPeriodOpen(), tapi layar tidak boleh menawarkan sesuatu yang
        // pasti ditolak. Baris semacam itu ditandai di sini supaya kotak centangnya hilang
        // dan aksi massal tidak pernah menyentuhnya. Satu-satunya jalan mengubahnya adalah
        // membatalkan closing-nya dulu (General Function → Cancel Closing) sehingga periodenya
        // kembali OPEN.
        $tutup = BsFiscalPeriod::where('status', 'closed')->get(['year', 'month'])
            ->map(fn ($p) => ((int) $p->year).'-'.((int) $p->month))->flip();
        $ditutup = fn ($j) => $tutup->has(((int) $j->period_year).'-'.((int) $j->period_month));

        $rows->getCollection()->transform(function ($j) use ($ditutup) {
            $j->period_closed = $ditutup($j);

            return $j;
        });

        // ── Jumlah per status untuk rentang yang sedang ditampilkan ─────────────────
        //
        // Aturan hitungnya SAMA PERSIS dengan General Function: jurnal pada periode tertutup
        // masuk kolom Closed dan TIDAK ikut dihitung sebagai Baru/Final/Approved. Kalau
        // aturannya berbeda, dua layar yang saling bertautan akan menyebut angka berbeda untuk
        // data yang sama — persis kebingungan yang hendak dihilangkan.
        $rekap = $saring(BsJournal::query()
            ->whereBetween('journal_date', [$from->toDateString(), $to->toDateString()]))
            ->selectRaw('period_year, period_month, status, COUNT(*) AS c')
            ->groupBy('period_year', 'period_month', 'status')->get();

        $jumlah = ['baru' => 0, 'final' => 0, 'approved' => 0, 'closed' => 0];
        foreach ($rekap as $r) {
            if ($ditutup($r)) {
                $jumlah['closed'] += (int) $r->c;

                continue;
            }
            $jumlah[match ($r->status) { 'final' => 'final', 'posted' => 'approved', default => 'baru' }] += (int) $r->c;
        }

        // ── Over / Under Budget per jurnal ──────────────────────────────────────────
        //
        // Aturannya SAMA PERSIS dengan gerbang yang menolak input (BudgetGuardService):
        // anggaran yang dipakai hanya yang berstatus disetujui, dicocokkan per
        // (tahun + akun + cost center), dan cost center efektif tiap baris = cost center
        // barisnya, jatuh ke cost center header bila kosong. Kalau layar memakai aturan
        // sendiri, ia akan menyebut "Under Budget" pada jurnal yang justru ditolak gerbangnya.
        //
        // Ada TIGA keadaan, bukan dua. Jurnal yang tak satu pun barisnya punya anggaran
        // TIDAK diberi keterangan hijau: mengatakan "Under Budget" pada sesuatu yang tak
        // beranggaran berarti menjanjikan pengawasan yang sebenarnya tidak ada.
        $anggaran = $this->statusAnggaran($rows->getCollection(), app(BudgetRealizationService::class));
        $rows->getCollection()->transform(function ($j) use ($anggaran) {
            $j->budget_status = $anggaran['status'][$j->id] ?? null;
            $j->budget_note   = $anggaran['catatan'][$j->id] ?? null;

            return $j;
        });

        // ── Isi pilihan tiap saringan kolom ─────────────────────────────────────────
        //
        // Dihitung dari RENTANG TANGGALnya saja, LEPAS dari saringan kolom lain. Kalau
        // pilihannya ikut disaring, memilih satu kolom akan mengosongkan pilihan kolom
        // lain dan orang tidak lagi bisa mengubah pikirannya tanpa mengosongkan semuanya.
        //
        // Dibatasi supaya rentang setahun pada bagan yang ramai tidak mengirim puluhan ribu
        // baris ke layar. Batasnya disebutkan ke layar, bukan disembunyikan — daftar yang
        // diam-diam terpotong akan terbaca sebagai "nilainya memang tidak ada".
        $BATAS = 500;
        $dalamRentang = fn () => BsJournal::query()
            ->whereBetween('journal_date', [$from->toDateString(), $to->toDateString()]);

        $daftar = function ($kolom) use ($dalamRentang, $BATAS) {
            return $dalamRentang()->whereNotNull($kolom)->where($kolom, '!=', '')
                ->distinct()->orderBy($kolom)->limit($BATAS)->pluck($kolom)
                ->map(fn ($v) => ['id' => (string) $v, 'label' => (string) $v])->values();
        };

        $idJurnal = $dalamRentang()->select('id');
        $opsi = [
            'number'    => $daftar('journal_number'),
            'date'      => $dalamRentang()->distinct()->orderByDesc('journal_date')->limit($BATAS)
                ->pluck('journal_date')
                ->map(fn ($v) => ['id' => \Illuminate\Support\Carbon::parse($v)->toDateString(),
                    'label' => \Illuminate\Support\Carbon::parse($v)->translatedFormat('d M Y')])->values(),
            'desc'      => $daftar('description'),
            'lines'     => BsJournalLine::whereIn('journal_id', $idJurnal)
                ->selectRaw('journal_id, COUNT(*) c')->groupBy('journal_id')->pluck('c')
                ->unique()->sort()->values()
                ->map(fn ($v) => ['id' => (string) $v, 'label' => $v.' baris'])->values(),
            'debit'     => $dalamRentang()->where('total_debit', '>', 0)->distinct()
                ->orderByDesc('total_debit')->limit($BATAS)->pluck('total_debit')
                ->map(fn ($v) => ['id' => (string) (float) $v, 'label' => number_format((float) $v, 2, ',', '.')])->values(),
            'credit'    => $dalamRentang()->where('total_credit', '>', 0)->distinct()
                ->orderByDesc('total_credit')->limit($BATAS)->pluck('total_credit')
                ->map(fn ($v) => ['id' => (string) (float) $v, 'label' => number_format((float) $v, 2, ',', '.')])->values(),
            // Akun & Keterangan Baris diambil dari baris jurnal pada rentang yang sama.
            'account'   => BsAccount::whereIn('id', BsJournalLine::whereIn('journal_id', $idJurnal)
                ->distinct()->pluck('account_id'))
                ->orderBy('code')->limit($BATAS)->get(['id', 'code', 'name'])
                ->map(fn ($a) => ['id' => (string) $a->id, 'label' => $a->code.' — '.$a->name])->values(),
            'line_desc' => BsJournalLine::whereIn('journal_id', $idJurnal)
                ->whereNotNull('description')->where('description', '!=', '')
                ->distinct()->orderBy('description')->limit($BATAS)->pluck('description')
                ->map(fn ($v) => ['id' => (string) $v, 'label' => (string) $v])->values(),
        ];

        // Master → "Jurnal" = ringkas (rincian tersembunyi). Query → "Detail Transaksi Jurnal" = rincian tampil.
        $isDetail = $request->routeIs('bs.gl.journal.detail');

        return Inertia::render('BusinessSupport/Gl/Journals/Index', [
            'journals'      => $rows,
            'sources'       => BsJournal::SOURCES,
            'statuses'      => BsJournal::STATUSES,
            // Rekap per status + tahun yang dirujuk, untuk tautan balik ke General Function.
            'jumlahStatus'  => $jumlah,
            'tahunRekap'    => (int) $to->year,
            'defaultDetail' => $isDetail,
            'listRoute'     => $isDetail ? 'bs.gl.journal.detail' : 'bs.gl.journal.index',
            'title'         => $isDetail ? 'Detail Transaksi Jurnal' : 'Jurnal',
            // Aksi massal Approve / Cancel Approved hanya untuk yang berwenang menyetujui.
            'canApprove'    => (bool) $request->user()?->can('businesssupport.approval.act'),
            // Isi pilihan tiap saringan kolom + batasnya, supaya layar bisa mengatakan
            // terus terang kalau daftarnya terpotong.
            'opsiKolom'     => $opsi,
            'batasOpsi'     => $BATAS,
            // Keterangan anggaran + pilihan saringannya.
            'budgetStatuses' => self::BUDGET_STATUSES,
            'filters'  => array_merge(
                $request->only([
                    'source', 'status', 'search',
                    'f_number', 'f_date', 'f_desc', 'f_lines', 'f_debit', 'f_credit',
                    'f_account', 'f_line_desc', 'f_budget',
                ]),
                ['date_from' => $from->toDateString(), 'date_to' => $to->toDateString()],
            ),
        ]);
    }

    /**
     * Finalisasi Jurnal — daftar jurnal "Baru" (draft) per periode + rincian, lalu finalisasi
     * (batch) yang sudah balance. Draft = Jurnal Memorial manual yang belum difinalisasi.
     */
    public function finalisasi(Request $request): Response
    {
        abort_unless($request->user()?->canModule('gl'), 403);

        $from = $request->date('date_from') ?? now()->startOfMonth();
        $to   = $request->date('date_to')   ?? now()->endOfMonth();

        $rows = BsJournal::query()
            ->with(['lines.account:id,code,name', 'costCenter:id,code,name', 'createdBy:id,name'])
            ->withCount('lines')
            ->where('status', 'draft')
            ->whereBetween('journal_date', [$from->toDateString(), $to->toDateString()])
            ->when($request->input('source'), fn ($q, $v) => $q->where('source', $v))
            ->when($request->input('search'), fn ($q, $s) => $q->where(fn ($w) => $w->where('journal_number', 'like', "%{$s}%")->orWhere('description', 'like', "%{$s}%")->orWhere('reference', 'like', "%{$s}%")))
            ->latest('journal_date')->latest('id')
            ->paginate(100)->withQueryString();

        return Inertia::render('BusinessSupport/Gl/Journals/Finalisasi', [
            'journals' => $rows,
            'sources'  => BsJournal::SOURCES,
            'filters'  => array_merge($request->only(['source', 'search']), ['date_from' => $from->toDateString(), 'date_to' => $to->toDateString()]),
        ]);
    }

    /**
     * Approval Jurnal — grid batch ala legacy. Default menampilkan jurnal "Final" (belum di-approve);
     * bisa dialihkan ke "Approved" untuk membatalkan approval. Approve = efektif di GL.
     */
    public function approval(Request $request): Response
    {
        abort_unless($request->user()?->canModule('gl'), 403);

        $from = $request->date('date_from') ?? now()->startOfMonth();
        $to   = $request->date('date_to')   ?? now()->endOfMonth();
        // Tab status: 'final' (menunggu approval) | 'posted' (sudah approved → bisa dibatalkan).
        $status = in_array($request->input('status'), ['final', 'posted'], true) ? $request->input('status') : 'final';

        $rows = BsJournal::query()
            ->with(['costCenter:id,code,name', 'createdBy:id,name'])
            ->withCount('lines')
            ->where('status', $status)
            ->whereBetween('journal_date', [$from->toDateString(), $to->toDateString()])
            ->when($request->input('source'), fn ($q, $v) => $q->where('source', $v))
            ->when($request->input('search'), fn ($q, $s) => $q->where(fn ($w) => $w->where('journal_number', 'like', "%{$s}%")->orWhere('description', 'like', "%{$s}%")->orWhere('reference', 'like', "%{$s}%")))
            ->orderBy('journal_date')->orderBy('id')
            ->paginate(100)->withQueryString();

        $counts = [
            'final'  => (int) BsJournal::where('status', 'final')->whereBetween('journal_date', [$from->toDateString(), $to->toDateString()])->count(),
            'posted' => (int) BsJournal::where('status', 'posted')->whereBetween('journal_date', [$from->toDateString(), $to->toDateString()])->count(),
        ];

        return Inertia::render('BusinessSupport/Gl/Journals/Approval', [
            'journals'   => $rows,
            'sources'    => BsJournal::SOURCES,
            'statuses'   => BsJournal::STATUSES,
            'statusTab'  => $status,
            'counts'     => $counts,
            'canApprove' => (bool) $request->user()?->can('businesssupport.approval.act'),
            'filters'    => array_merge($request->only(['source', 'search']), ['date_from' => $from->toDateString(), 'date_to' => $to->toDateString()]),
        ]);
    }

    /** Batch finalisasi: draft (balance) → final. */
    public function finalizeBatch(Request $request, JournalService $svc): RedirectResponse
    {
        abort_unless($request->user()?->canModule('gl'), 403);

        return $this->batch($request, fn (BsJournal $j) => $svc->finalize($j), 'difinalisasi (Final)');
    }

    /** Batch approval: final → posted (efektif di GL). */
    public function approveBatch(Request $request, JournalService $svc): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.approval.act'), 403);
        $uid = $request->user()->id;

        return $this->batch($request, fn (BsJournal $j) => $svc->approve($j, $uid), 'di-approve (efektif di GL)');
    }

    /** Batch cancel approval: posted → final (batalkan approval). */
    public function cancelBatch(Request $request, JournalService $svc): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.approval.act'), 403);

        return $this->batch($request, fn (BsJournal $j) => $svc->unapprove($j), 'dibatalkan approval-nya (kembali Final)');
    }

    /**
     * Batch batal finalisasi: final → draft.
     *
     * Jurnal Approved sengaja TIDAK ikut: rantainya satu langkah demi satu langkah, jadi
     * approval-nya harus dibatalkan lebih dulu. JournalService::reject() menolaknya dengan
     * pesan yang menerangkan urutannya, dan penolakan itu terhitung sebagai "gagal" di
     * ringkasan batch — operator tahu persis berapa yang tidak ikut dan mengapa.
     */
    public function cancelFinalizeBatch(Request $request, JournalService $svc): RedirectResponse
    {
        abort_unless($request->user()?->canModule('gl'), 403);

        return $this->batch($request, fn (BsJournal $j) => $svc->reject($j), 'dibatalkan finalisasinya (kembali Baru)');
    }

    /**
     * Jalankan aksi batch atas daftar id jurnal; hitung sukses & gagal (gagal tidak menghentikan sisanya).
     *
     * @param  \Closure(BsJournal):mixed  $action
     */
    private function batch(Request $request, \Closure $action, string $verb): RedirectResponse
    {
        $v = $request->validate([
            'ids'   => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $ok = 0;
        $fail = 0;
        $lastError = null;
        foreach (BsJournal::whereIn('id', $v['ids'])->get() as $journal) {
            try {
                $action($journal);
                $ok++;
            } catch (BusinessRuleException $e) {
                $fail++;
                $lastError = $e->getMessage();
            }
        }

        $msg = "{$ok} jurnal {$verb}.";
        if ($fail > 0) {
            $msg .= " {$fail} gagal".($lastError ? " (mis. {$lastError})" : '').'.';
        }

        return back()->with($fail > 0 && $ok === 0 ? 'error' : 'success', $msg);
    }

    /** Export Excel: semua info Detail Transaksi Jurnal (header + semua baris akun) mengikuti filter layar. */
    public function export(Request $request, JournalPartyResolver $party): \Illuminate\Http\Response
    {
        abort_unless($request->user()?->canModule('gl'), 403);

        $from = $request->date('date_from') ?? now()->startOfMonth();
        $to   = $request->date('date_to')   ?? now()->endOfMonth();

        $journals = BsJournal::query()
            ->with(['lines.account:id,code,name', 'costCenter:id,code,name'])
            ->whereBetween('journal_date', [$from->toDateString(), $to->toDateString()])
            ->when($request->input('source'), fn ($q, $v) => $q->where('source', $v))
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->input('search'), fn ($q, $s) => $q->where(fn ($w) => $w->where('journal_number', 'like', "%{$s}%")->orWhere('description', 'like', "%{$s}%")->orWhere('reference', 'like', "%{$s}%")))
            ->latest('journal_date')->latest('id')
            ->get();

        $e  = fn ($v) => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES);
        $rp = fn ($v) => number_format((float) ($v ?? 0), 2, ',', '.');
        $src = BsJournal::SOURCES;
        $st  = BsJournal::STATUSES;

        // Pihak Terkait — nama supplier/employee/customer di balik tiap jurnal. Di-resolve
        // sekali untuk SELURUH jurnal periode ini (batch), bukan per baris → bukan N+1.
        $pihak = $party->forJournalIds($journals->pluck('id')->all());

        // Urutan kolom: Kode Akun & Nama Akun paling kiri, lalu Pihak Terkait tepat setelah Sumber.
        $h = '<table border="1"><tr style="background:#0f766e;color:#fff">'
            .'<th>Kode Akun</th><th>Nama Akun</th>'
            .'<th>No. Jurnal</th><th>Tanggal</th><th>Sumber</th><th>Pihak Terkait</th><th>Status</th><th>Keterangan</th><th>Referensi</th>'
            .'<th>Cost Center</th><th>Keterangan Baris</th><th>Debit</th><th>Kredit</th></tr>';
        $totalD = 0.0;
        $totalK = 0.0;
        foreach ($journals as $j) {
            foreach ($j->lines as $ln) {
                $totalD += (float) $ln->debit;
                $totalK += (float) $ln->credit;
                $h .= '<tr>'
                    .'<td>'.$e($ln->account?->code).'</td>'
                    .'<td>'.$e($ln->account?->name).'</td>'
                    .'<td>'.$e($j->journal_number).'</td>'
                    .'<td>'.$e(optional($j->journal_date)->toDateString()).'</td>'
                    .'<td>'.$e($src[$j->source] ?? $j->source).'</td>'
                    .'<td>'.$e($pihak[$j->id] ?? '').'</td>'
                    .'<td>'.$e($st[$j->status] ?? $j->status).'</td>'
                    .'<td>'.$e($j->description).'</td>'
                    .'<td>'.$e($j->reference).'</td>'
                    .'<td>'.$e($j->costCenter?->name).'</td>'
                    .'<td>'.$e($ln->description).'</td>'
                    .'<td>'.$rp($ln->debit).'</td>'
                    .'<td>'.$rp($ln->credit).'</td>'
                    .'</tr>';
            }
        }
        $h .= '<tr style="font-weight:bold;background:#f1f5f9"><td colspan="11">TOTAL</td><td>'.$rp($totalD).'</td><td>'.$rp($totalK).'</td></tr></table>';

        $file = 'Detail-Transaksi-Jurnal-'.$from->format('Ymd').'-'.$to->format('Ymd').'.xls';

        return response('<html xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="utf-8"></head><body>'
            .'<h3>DETAIL TRANSAKSI JURNAL</h3><p>Periode: '.$e($from->toDateString()).' s/d '.$e($to->toDateString()).'</p>'
            .$h.'</body></html>')
            ->header('Content-Type', 'application/vnd.ms-excel; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="'.$file.'"');
    }

    /**
     * Lengkapi cost center tiap baris jurnal.
     *
     * Tiap COA boleh dibebankan ke cost center sendiri. Baris yang dikosongkan mewarisi cost
     * center header, sehingga dimensi GL tidak pernah kosong dan perilaku jurnal lama (satu
     * cost center untuk seluruh baris) tetap sama persis.
     *
     * PENGECUALIAN: akun bertanda `cost_center_optional` (lihat CoaTanpaCostCenterSeeder) boleh
     * BENAR-BENAR tanpa cost center. Barisnya TIDAK diwarisi cost center header — kalau diwarisi,
     * mengosongkan kolom itu tidak akan pernah berpengaruh dan nilai neraca ikut terstempel ke
     * cost center yang sebenarnya tidak menanggungnya.
     *
     * @param  array<int,array<string,mixed>>  $lines
     * @param  array<int,bool>  $optional  account_id → boleh tanpa cost center
     * @return array<int,array<string,mixed>>
     */
    private function withLineCostCenter(array $lines, ?int $headerCostCenterId, array $optional = []): array
    {
        return array_map(function (array $l) use ($headerCostCenterId, $optional): array {
            $cc = $l['cost_center_id'] ?? null;
            $cc = ($cc === null || $cc === '') ? null : (int) $cc;
            if ($cc === null && empty($optional[(int) ($l['account_id'] ?? 0)])) {
                $cc = $headerCostCenterId;   // akun biasa: warisi header seperti sebelumnya
            }
            $l['cost_center_id'] = $cc;

            return $l;
        }, $lines);
    }

    /**
     * Akun mana saja (dari daftar id ini) yang boleh dijurnal tanpa cost center.
     *
     * @param  array<int,mixed>  $accountIds
     * @return array<int,bool>
     */
    private function costCenterOptionalMap(array $accountIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $accountIds))));
        if (! $ids) {
            return [];
        }

        return BsAccount::whereIn('id', $ids)->where('cost_center_optional', true)
            ->pluck('id')->mapWithKeys(fn ($id) => [(int) $id => true])->all();
    }

    /**
     * Baris berakun WAJIB cost center tetapi tidak punya satu pun (baris kosong & header kosong).
     *
     * @param  array<int,array<string,mixed>>  $lines  hasil withLineCostCenter()
     * @return array<int,string>  nama akun yang bermasalah
     */
    private function missingCostCenter(array $lines): array
    {
        $ids = [];
        foreach ($lines as $l) {
            if (($l['cost_center_id'] ?? null) === null) {
                $ids[] = (int) ($l['account_id'] ?? 0);
            }
        }
        $ids = array_values(array_unique(array_filter($ids)));
        if (! $ids) {
            return [];
        }

        return BsAccount::whereIn('id', $ids)->where(fn ($q) => $q->where('cost_center_optional', false)->orWhereNull('cost_center_optional'))
            ->get(['code', 'name'])->map(fn ($a) => $a->code.' - '.$a->name)->all();
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()?->canModule('gl'), 403);

        return Inertia::render('BusinessSupport/Gl/Journals/Form', [
            'accounts'    => BsAccount::where('is_active', true)->where('is_postable', true)->orderBy('code')->get(['id', 'code', 'name', 'type', 'cost_center_optional']),
            'costCenters' => BsCostCenter::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    public function store(Request $request, JournalService $svc, \App\Domain\BusinessSupport\Services\BudgetGuardService $guard): RedirectResponse
    {
        abort_unless($request->user()?->canModule('gl'), 403);

        $v = $request->validate([
            'journal_date'   => ['required', 'date'],
            'reference'      => ['nullable', 'string', 'max:150'],
            'description'    => ['required', 'string', 'max:255'],
            // Boleh kosong: sebagian akun neraca memang tidak memakai cost center. Kelengkapannya
            // diperiksa PER BARIS di bawah, sesuai penanda cost_center_optional tiap akun.
            'cost_center_id' => ['nullable', 'integer', 'exists:bs_cost_centers,id'],
            'lines'                 => ['required', 'array', 'min:2'],
            'lines.*.account_id'    => ['required', 'integer', 'exists:bs_accounts,id'],
            // Cost center PER BARIS (opsional) — tiap COA boleh dibebankan ke cost center sendiri.
            // Bila dikosongkan, dipakai cost center header sebagai bawaan (lihat withLineCostCenter).
            'lines.*.cost_center_id' => ['nullable', 'integer', 'exists:bs_cost_centers,id'],
            'lines.*.debit'         => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit'        => ['nullable', 'numeric', 'min:0'],
            'lines.*.description'   => ['nullable', 'string', 'max:200'],
        ]);

        $headerCc = isset($v['cost_center_id']) ? (int) $v['cost_center_id'] : null;
        $lines = $this->withLineCostCenter(
            $v['lines'],
            $headerCc,
            $this->costCenterOptionalMap(array_column($v['lines'], 'account_id')),
        );
        if ($kurang = $this->missingCostCenter($lines)) {
            return back()->withInput()->with('error', 'Cost center wajib diisi untuk akun: '.implode(', ', $kurang).'.');
        }

        try {
            // Gerbang over-budget: cost center tiap baris SUDAH final di atas (akun biasa mewarisi
            // header, akun bebas cost center tetap null), jadi header tidak dipakai lagi sebagai
            // cadangan — kalau dipakai, baris yang sengaja dikosongkan ikut terhitung ke cost
            // center header dan bisa memicu OVER_BUDGET palsu.
            $guard->assertWithinBudget($lines, null, $v['journal_date']);
            $journal = $svc->post([
                'journal_date'   => $v['journal_date'],
                'source'         => 'memorial',
                'status'         => 'draft', // jurnal memorial manual mulai sebagai Baru
                'reference'      => $v['reference'] ?? null,
                'description'    => $v['description'],
                'cost_center_id' => $v['cost_center_id'] ?? null,
                'created_by'     => $request->user()->id,
            ], $lines);
        } catch (BusinessRuleException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('bs.gl.journal.show', $journal)->with('success', "Jurnal {$journal->journal_number} dibuat sebagai Baru — finalisasi & approve agar efektif di GL.");
    }

    public function show(Request $request, BsJournal $journal): Response
    {
        abort_unless($request->user()?->canModule('gl'), 403);

        return Inertia::render('BusinessSupport/Gl/Journals/Show', [
            'journal'  => $journal->load(['lines.account:id,code,name', 'costCenter:id,code,name']),
            'sources'  => BsJournal::SOURCES,
            'statuses' => BsJournal::STATUSES,
        ]);
    }

    /** #54: form edit jurnal (koreksi pencatatan). */
    public function edit(Request $request, BsJournal $journal): Response|RedirectResponse
    {
        abort_unless($request->user()?->canModule('gl'), 403);

        // Jurnal pada periode yang sudah CLOSED tidak dapat diedit hingga closing dibuka (Closing Fiskal).
        $period = BsFiscalPeriod::where('tenant_id', $journal->tenant_id)
            ->where('year', $journal->period_year)->where('month', $journal->period_month)->first();
        if ($period && $period->status === 'closed') {
            return redirect()->route('bs.gl.journal.show', $journal)
                ->with('error', "Periode {$journal->period_year}/{$journal->period_month} sudah CLOSED — jurnal tidak dapat diedit hingga closing dibuka.");
        }

        return Inertia::render('BusinessSupport/Gl/Journals/Form', [
            'accounts'    => BsAccount::where('is_active', true)->where('is_postable', true)->orderBy('code')->get(['id', 'code', 'name', 'type', 'cost_center_optional']),
            'costCenters' => BsCostCenter::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'editing'     => array_merge(
                $journal->only(['id', 'journal_number', 'journal_date', 'reference', 'description', 'cost_center_id', 'source', 'status']),
                // cost_center_id per baris WAJIB ikut dimuat — tanpa ini, mengedit jurnal akan
                // mengembalikan baris tanpa cost center dan diam-diam menimpanya dgn cost center header.
                ['lines' => $journal->lines()->get(['account_id', 'cost_center_id', 'description', 'debit', 'credit'])->toArray()],
            ),
        ]);
    }

    /** #54: simpan perubahan jurnal (ganti baris, tetap balance). */
    public function update(Request $request, BsJournal $journal, JournalService $svc, \App\Domain\BusinessSupport\Services\BudgetGuardService $guard): RedirectResponse
    {
        abort_unless($request->user()?->canModule('gl'), 403);

        $v = $request->validate([
            'journal_date'        => ['required', 'date'],
            'reference'           => ['nullable', 'string', 'max:150'],
            'description'         => ['required', 'string', 'max:255'],
            // Lihat catatan pada store(): kelengkapan cost center diperiksa per baris.
            'cost_center_id'      => ['nullable', 'integer', 'exists:bs_cost_centers,id'],
            'lines'               => ['required', 'array', 'min:2'],
            'lines.*.account_id'  => ['required', 'integer', 'exists:bs_accounts,id'],
            // Cost center per baris — lihat catatan pada store().
            'lines.*.cost_center_id' => ['nullable', 'integer', 'exists:bs_cost_centers,id'],
            'lines.*.debit'       => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit'      => ['nullable', 'numeric', 'min:0'],
            'lines.*.description' => ['nullable', 'string', 'max:200'],
        ]);

        $headerCc = isset($v['cost_center_id']) ? (int) $v['cost_center_id'] : null;
        $lines = $this->withLineCostCenter(
            $v['lines'],
            $headerCc,
            $this->costCenterOptionalMap(array_column($v['lines'], 'account_id')),
        );
        if ($kurang = $this->missingCostCenter($lines)) {
            return back()->withInput()->with('error', 'Cost center wajib diisi untuk akun: '.implode(', ', $kurang).'.');
        }

        try {
            // Gerbang over-budget (kecualikan jurnal ini dari realisasi agar tak dihitung ganda saat
            // edit). Header tidak dipakai sebagai cadangan — lihat catatan pada store().
            $guard->assertWithinBudget($lines, null, $v['journal_date'], $journal->id);
            $svc->update($journal, [
                'journal_date'   => $v['journal_date'],
                'reference'      => $v['reference'] ?? null,
                'description'    => $v['description'],
                'cost_center_id' => $v['cost_center_id'] ?? null,
            ], $lines);
        } catch (BusinessRuleException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('bs.gl.journal.show', $journal)->with('success', "Jurnal {$journal->journal_number} diperbarui.");
    }

    public function finalize(Request $request, BsJournal $journal, JournalService $svc): RedirectResponse
    {
        abort_unless($request->user()?->canModule('gl'), 403);

        return $this->transition(fn () => $svc->finalize($journal), $journal, 'Jurnal difinalisasi (Final).');
    }

    public function approve(Request $request, BsJournal $journal, JournalService $svc): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.approval.act'), 403);

        return $this->transition(fn () => $svc->approve($journal, $request->user()->id), $journal, 'Jurnal di-approve — efektif di GL.');
    }

    /** Final → Baru (batal finalisasi). Jurnal Approved ditolak service-nya. */
    public function reject(Request $request, BsJournal $journal, JournalService $svc): RedirectResponse
    {
        abort_unless($request->user()?->canModule('gl'), 403);

        return $this->transition(fn () => $svc->reject($journal), $journal, 'Finalisasi dibatalkan — jurnal kembali ke Baru.');
    }

    /** Approved → Final (batal approval). Langkah WAJIB sebelum bisa kembali ke Baru. */
    public function unapprove(Request $request, BsJournal $journal, JournalService $svc): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.approval.act'), 403);

        return $this->transition(fn () => $svc->unapprove($journal), $journal, 'Approval dibatalkan — jurnal kembali ke Final.');
    }

    private function transition(callable $fn, BsJournal $journal, string $message): RedirectResponse
    {
        try {
            $fn();
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('bs.gl.journal.show', $journal)->with('success', $message);
    }
}
