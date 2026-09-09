<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Budget;

use App\Domain\BusinessSupport\Models\BsBudget;
use App\Domain\BusinessSupport\Models\BsBudgetAllocation;
use App\Domain\BusinessSupport\Models\BsBudgetCorrection;
use App\Domain\BusinessSupport\Models\BsCostCenter;
use App\Domain\BusinessSupport\Services\BudgetRealizationService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
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
