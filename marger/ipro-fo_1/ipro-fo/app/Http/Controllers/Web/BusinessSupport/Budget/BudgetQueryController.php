<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Budget;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsBudget;
use App\Domain\BusinessSupport\Models\BsBudgetAllocation;
use App\Domain\BusinessSupport\Models\BsBudgetCorrection;
use App\Domain\BusinessSupport\Models\BsCostCenter;
use App\Domain\BusinessSupport\Services\BudgetRealizationService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Query / Laporan Budget Control (BO: QueryMasterBudget / QueryMutasi / QueryMultiBudget / QueryAlokasiBudget).
 */
class BudgetQueryController extends Controller
{
    public function __construct(private readonly BudgetRealizationService $realisasi) {}

    private function filters(Request $request): array
    {
        return [
            'year' => (int) $request->integer('year', (int) now()->year),
            'cost_center_id' => $request->integer('cost_center_id') ?: null,
            // Filter periode: tanggal awal & tanggal akhir (opsional).
            'date_from' => $request->input('date_from') ?: null,
            'date_to'   => $request->input('date_to') ?: null,
        ];
    }

    /** Laporan Master Budget — hanya anggaran yang SUDAH di-approve. */
    public function master(Request $request): Response
    {
        abort_unless($request->user()?->can('budget.query.master'), 403);
        $f = $this->filters($request);

        $rows = BsBudget::with(['account:id,code,name', 'costCenter:id,code,name'])
            ->where('year', $f['year'])->where('status', 'approved')
            ->when($f['cost_center_id'], fn ($q, $id) => $q->where('cost_center_id', $id))
            ->when($f['date_from'], fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
            ->when($f['date_to'], fn ($q, $d) => $q->whereDate('created_at', '<=', $d))
            ->orderBy('doc_no')->get()
            ->map(fn ($b) => [
                'doc_no' => $b->doc_no, 'account' => $b->account?->code.' - '.$b->account?->name,
                'cost_center' => $b->costCenter?->name ?? 'Semua', 'amount' => (float) $b->amount,
                'months' => array_values($b->monthsArray()), 'status' => $b->status,
            ])->values();

        return Inertia::render('BusinessSupport/Budget/Query/Master', [
            'filters' => $f, 'rows' => $rows, 'monthLabels' => BsBudget::MONTHS,
            'costCenters' => $this->ccOptions(), 'total' => $rows->sum('amount'),
        ]);
    }

    /** Laporan Realisasi Budget — matriks budget vs aktual per akun per bulan. */
    public function realization(Request $request): Response
    {
        abort_unless($request->user()?->can('budget.query.realization'), 403);
        $f = $this->filters($request);

        $rows = BsBudget::with(['account:id,code,name', 'costCenter:id,code,name'])
            ->where('year', $f['year'])->where('status', 'approved')
            ->when($f['cost_center_id'], fn ($q, $id) => $q->where('cost_center_id', $id))
            ->orderBy('doc_no')->get()
            ->map(function ($b) use ($f) {
                $budgetM = $b->monthsArray();
                // Realisasi dibatasi rentang tanggal (jika diisi) pada tanggal jurnal.
                $realM   = $this->realisasi->byMonth($f['year'], $b->account_id, $b->cost_center_id, $f['date_from'], $f['date_to']);
                $months  = [];
                $sumB = 0.0;
                $sumR = 0.0;
                for ($m = 1; $m <= 12; $m++) {
                    $bv = (float) ($budgetM[$m] ?? 0);
                    $rv = (float) ($realM[$m] ?? 0);
                    $months[] = ['budget' => $bv, 'real' => $rv, 'pct' => $bv > 0 ? round($rv / $bv * 100, 1) : 0];
                    $sumB += $bv;
                    $sumR += $rv;
                }

                return [
                    'account' => $b->account?->code.' - '.$b->account?->name, 'cost_center' => $b->costCenter?->name ?? 'Semua',
                    'months' => $months, 'budget' => $sumB, 'real' => $sumR, 'sisa' => $sumB - $sumR,
                    'pct' => $sumB > 0 ? round($sumR / $sumB * 100, 1) : 0,
                ];
            })->values();

        return Inertia::render('BusinessSupport/Budget/Query/Realization', [
            'filters' => $f, 'rows' => $rows, 'monthLabels' => BsBudget::MONTHS, 'costCenters' => $this->ccOptions(),
            'total' => ['budget' => $rows->sum('budget'), 'real' => $rows->sum('real'), 'sisa' => $rows->sum('sisa')],
        ]);
    }

    /** Laporan Alokasi Budget. */
    public function allocation(Request $request): Response
    {
        abort_unless($request->user()?->can('budget.query.allocation'), 403);
        $f = $this->filters($request);

        $rows = BsBudgetAllocation::with(['account:id,code,name', 'costCenter:id,code,name', 'budget:id,doc_no'])
            ->where('year', $f['year'])->where('status', 'approved')
            ->when($f['cost_center_id'], fn ($q, $id) => $q->where('cost_center_id', $id))
            ->when($f['date_from'], fn ($q, $d) => $q->whereDate('alloc_date', '>=', $d))
            ->when($f['date_to'], fn ($q, $d) => $q->whereDate('alloc_date', '<=', $d))
            ->orderBy('doc_no')->get()
            ->map(fn ($a) => [
                'doc_no' => $a->doc_no, 'ref' => $a->budget?->doc_no, 'date' => $a->alloc_date?->format('Y-m-d'),
                'account' => $a->account?->code.' - '.$a->account?->name, 'cost_center' => $a->costCenter?->name ?? 'Semua',
                'amount' => (float) $a->amount, 'total_budget' => (float) $a->total_budget, 'status' => $a->status,
            ])->values();

        return Inertia::render('BusinessSupport/Budget/Query/Allocation', [
            'filters' => $f, 'rows' => $rows, 'costCenters' => $this->ccOptions(), 'total' => $rows->sum('amount'),
        ]);
    }

    /**
     * Report Budget — anggaran vs realisasi per cost center, satu blok per cost center.
     *
     * Tiga hal yang membedakannya dari layar Realisasi Budget:
     *  1. Barisnya per AKUN, bukan per dokumen anggaran. Indeks unik bs_budget_unique menjaga
     *     satu dokumen per (tenant + tahun + cost center + akun), TETAPI MySQL menganggap NULL
     *     selalu berbeda — jadi anggaran lintas cost center (cost_center_id kosong) boleh
     *     berulang untuk akun yang sama. Anggarannya dijumlahkan dan realisasinya dihitung
     *     SEKALI; bila tiap dokumen dihitung sendiri, realisasi yang sama terhitung berulang.
     *  2. Akun yang punya realisasi TANPA anggaran ikut tampil (anggaran Rp 0, sisa negatif).
     *     Justru itu yang paling perlu terlihat: belanja yang tidak pernah dianggarkan.
     *  3. Angkanya dipotong bersamaan. Bila laporan dibatasi s/d bulan tertentu, anggaran diambil
     *     m1..mN dan realisasi dibatasi tanggal jurnal s/d akhir bulan N — bukan anggaran setahun
     *     penuh dibanding realisasi separuh tahun, yang selalu terlihat aman padahal belum tentu.
     */
    public function report(Request $request): Response
    {
        // Sengaja memakai izin yang sama dengan Realisasi Budget: isinya pertanyaan yang sama
        // (anggaran vs realisasi), hanya dipotong per cost center. Tidak ada izin baru yang harus
        // di-seed lebih dulu sebelum menunya bisa dibuka.
        abort_unless($request->user()?->can('budget.query.realization'), 403);

        $year   = (int) $request->integer('year', (int) now()->year);
        $ccOnly = $request->integer('cost_center_id') ?: null;
        $sd     = min(12, max(1, (int) $request->integer('sd_bulan', 12)));
        [$from, $to] = $sd < 12
            ? [sprintf('%04d-01-01', $year), Carbon::create($year, $sd, 1)->endOfMonth()->toDateString()]
            : [null, null];

        $akun  = BsAccount::orderBy('code')->get(['id', 'code', 'name'])->keyBy('id');
        // withTrashed: cost center yang sudah dihapus masih dirujuk anggaran & jurnal lama —
        // tanpa ini bloknya muncul tanpa nama.
        $pusat = BsCostCenter::withTrashed()->orderBy('code')->get(['id', 'code', 'name'])->keyBy('id');

        // Kunci "costCenterId|accountId"; anggaran tanpa cost center memakai "semua|accountId"
        // supaya TIDAK bertabrakan dengan realisasi yang cost center-nya memang kosong ("0|…").
        $plafon = [];
        $daftar = BsBudget::where('year', $year)->where('status', 'approved')
            ->when($ccOnly, fn ($q, $id) => $q->where('cost_center_id', $id))->get();
        foreach ($daftar as $b) {
            $kunci = ($b->cost_center_id ? (int) $b->cost_center_id : 'semua').'|'.(int) $b->account_id;
            $bulan = $b->monthsArray();
            // s/d Des memakai amount (angka yang sama dengan plafon gerbang over-budget);
            // pemotongan bulan memakai m1..mN.
            $nilai = $sd < 12 ? array_sum(array_slice($bulan, 0, $sd, true)) : (float) $b->amount;
            $plafon[$kunci] = ($plafon[$kunci] ?? 0.0) + (float) $nilai;
        }

        $real = $this->realisasi->perCostCenter($year, $from, $to);
        $grup = [];
        foreach (array_unique(array_merge(array_keys($plafon), array_keys($real))) as $kunci) {
            [$cc, $accId] = explode('|', (string) $kunci);
            $accId = (int) $accId;
            if ($ccOnly !== null && $cc !== (string) $ccOnly) {
                continue;
            }
            $anggaran = round((float) ($plafon[$kunci] ?? 0.0), 2);
            // Anggaran "Semua cost center" adalah plafon LINTAS cost center, jadi realisasinya pun
            // dihitung lintas cost center — persis seperti yang dipakai gerbang over-budget.
            $realisasi = $cc === 'semua'
                ? $this->realisasi->total($year, $accId, null, $from, $to)
                : round((float) ($real[$kunci] ?? 0.0), 2);
            if (abs($anggaran) < 0.005 && abs($realisasi) < 0.005) {
                continue; // tidak dianggarkan dan tidak dipakai — tidak menambah apa pun
            }
            $a = $akun[$accId] ?? null;
            $grup[$cc][] = [
                'code' => (string) ($a?->code ?? $accId), 'name' => (string) ($a?->name ?? 'Akun #'.$accId),
            ] + $this->angka($anggaran, $realisasi);
        }

        $sections = $this->blok($grup, $pusat);

        return Inertia::render('BusinessSupport/Budget/Query/Report', [
            'filters'     => ['year' => $year, 'cost_center_id' => $ccOnly, 'sd_bulan' => $sd],
            'sections'    => $sections,
            'total'       => $this->totalKeseluruhan($sections),
            'costCenters' => $this->ccOptions(),
            'monthLabels' => BsBudget::MONTHS,
        ]);
    }

    /**
     * Lima angka yang sama untuk baris, ringkasan cost center, dan total keseluruhan — satu tempat
     * supaya ringkasannya tidak mungkin memakai rumus yang berbeda dari barisnya.
     *
     * @return array<string,mixed>
     */
    private function angka(float $anggaran, float $realisasi): array
    {
        $sisa = round($anggaran - $realisasi, 2);

        return [
            'budget' => $anggaran, 'real' => $realisasi, 'sisa' => $sisa,
            // Tanpa anggaran, "sisa" tidak punya pembagi. Belanja tanpa anggaran ditandai −100%:
            // seluruhnya di luar anggaran. Realisasi NEGATIF (netto kembali) tanpa anggaran bukan
            // "over" apa pun — sisanya malah positif — jadi 0%, bukan −100%.
            'pct_sisa' => $anggaran > 0.005 ? round($sisa / $anggaran * 100, 1) : ($realisasi > 0.005 ? -100.0 : 0.0),
            // Absorpsi sengaja null bila tak ada anggaran: pembagian dengan nol bukan "0% terpakai".
            // Layar menampilkannya sebagai "—", bukan sebagai batang kosong yang terbaca aman.
            'absorpsi' => $anggaran > 0.005 ? round($realisasi / $anggaran * 100, 1) : null,
        ];
    }

    /**
     * Susun blok per cost center: menurut kode cost center, lalu "Tanpa Cost Center", lalu
     * "Semua Cost Center" paling bawah.
     *
     * @param  array<string,array<int,array<string,mixed>>>  $grup
     * @return array<int,array<string,mixed>>
     */
    private function blok(array $grup, \Illuminate\Support\Collection $pusat): array
    {
        $urutan = [];
        foreach ($pusat as $id => $c) {
            if (isset($grup[(string) $id])) {
                $urutan[] = (string) $id;
            }
        }
        foreach (array_keys($grup) as $k) {
            // Cost center yang tidak ada lagi di master tetap ditampilkan, bukan dibuang diam-diam.
            if (! in_array((string) $k, ['semua', '0'], true) && ! in_array((string) $k, $urutan, true)) {
                $urutan[] = (string) $k;
            }
        }
        foreach (['0', 'semua'] as $k) {
            if (isset($grup[$k])) {
                $urutan[] = $k;
            }
        }

        $out = [];
        foreach ($urutan as $k) {
            $rows = $grup[$k];
            usort($rows, fn ($a, $b) => strcmp((string) $a['code'], (string) $b['code']));
            $c = in_array($k, ['semua', '0'], true) ? null : ($pusat[(int) $k] ?? null);
            $out[] = [
                'key'    => $k,
                'name'   => match ($k) {
                    'semua' => 'Semua Cost Center',
                    '0'     => 'Tanpa Cost Center',
                    default => (string) ($c?->name ?? 'Cost Center #'.$k),
                },
                'code'   => $c?->code,
                // Blok lintas cost center TIDAK ikut total keseluruhan: realisasinya dihitung
                // lintas cost center, jadi angkanya sudah ada di dalam blok-blok di atasnya.
                'lintas' => $k === 'semua',
                'rows'   => array_values($rows),
                'summary' => ['akun' => count($rows)] + $this->angka(
                    round(array_sum(array_column($rows, 'budget')), 2),
                    round(array_sum(array_column($rows, 'real')), 2),
                ),
            ];
        }

        return $out;
    }

    /**
     * @param  array<int,array<string,mixed>>  $sections
     * @return array<string,mixed>
     */
    private function totalKeseluruhan(array $sections): array
    {
        $ikut = array_values(array_filter($sections, fn ($s) => ! $s['lintas']));

        return [
            'akun'        => array_sum(array_map(fn ($s) => $s['summary']['akun'], $ikut)),
            'cost_center' => count($ikut),
            'lintas'      => count($sections) - count($ikut),
        ] + $this->angka(
            round(array_sum(array_map(fn ($s) => (float) $s['summary']['budget'], $ikut)), 2),
            round(array_sum(array_map(fn ($s) => (float) $s['summary']['real'], $ikut)), 2),
        );
    }

    /** Laporan Koreksi Budget. */
    public function correction(Request $request): Response
    {
        abort_unless($request->user()?->can('budget.query.correction'), 403);
        $f = $this->filters($request);

        $rows = BsBudgetCorrection::with(['account:id,code,name', 'costCenter:id,code,name', 'budget:id,doc_no'])
            ->where('year', $f['year'])->where('status', 'approved')
            ->when($f['cost_center_id'], fn ($q, $id) => $q->where('cost_center_id', $id))
            ->when($f['date_from'], fn ($q, $d) => $q->whereDate('corr_date', '>=', $d))
            ->when($f['date_to'], fn ($q, $d) => $q->whereDate('corr_date', '<=', $d))
            ->orderBy('doc_no')->get()
            ->map(fn ($c) => [
                'doc_no' => $c->doc_no, 'ref' => $c->budget?->doc_no, 'date' => $c->corr_date?->format('Y-m-d'),
                'account' => $c->account?->code.' - '.$c->account?->name, 'cost_center' => $c->costCenter?->name ?? 'Semua',
                'old_amount' => (float) $c->old_amount, 'new_amount' => (float) $c->new_amount,
                'selisih' => round((float) $c->new_amount - (float) $c->old_amount, 2), 'status' => $c->status,
            ])->values();

        return Inertia::render('BusinessSupport/Budget/Query/Correction', [
            'filters' => $f, 'rows' => $rows, 'costCenters' => $this->ccOptions(),
        ]);
    }

    /** @return array<int,array<string,mixed>> */
    private function ccOptions(): array
    {
        return BsCostCenter::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name'])->toArray();
    }
}
