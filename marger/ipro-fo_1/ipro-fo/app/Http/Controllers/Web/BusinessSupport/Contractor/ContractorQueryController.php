<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Contractor;

use App\Domain\BusinessSupport\Models\BsProject;
use App\Domain\BusinessSupport\Models\BsWorkOrder;
use App\Domain\BusinessSupport\Models\BsWorkOrderMaterial;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Query / Laporan Contractor Management. */
class ContractorQueryController extends Controller
{
    private function filters(Request $request): array
    {
        return [
            'project_id' => $request->integer('project_id') ?: null,
            'jenis'      => $request->input('jenis') ?: null,
        ];
    }

    private function projectOpts(): array
    {
        return BsProject::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name'])->toArray();
    }

    /** Rekap SPK. */
    public function rekap(Request $request): Response
    {
        abort_unless($request->user()?->canModule('contractor'), 403);
        $f = $this->filters($request);

        $rows = BsWorkOrder::with(['supplier:id,name', 'project:id,code,name'])
            ->when($f['project_id'], fn ($q, $id) => $q->where('project_id', $id))
            ->when($f['jenis'], fn ($q, $j) => $q->where('jenis', $j))
            ->orderByDesc('spk_date')->get()
            ->map(fn ($w) => [
                'spk_number' => $w->spk_number, 'date' => $w->spk_date?->format('Y-m-d'), 'title' => $w->title,
                'supplier' => $w->supplier?->name, 'project' => $w->project?->name, 'jenis' => $w->jenis,
                'contract' => (float) $w->contract_value, 'progress' => (float) $w->progress_value,
                'retention' => (float) $w->retention_held, 'remaining' => (float) $w->remaining_value, 'status' => $w->status,
            ])->values();

        return Inertia::render('BusinessSupport/Contractor/Query/Rekap', [
            'filters' => $f, 'projects' => $this->projectOpts(), 'jenisOpts' => BsWorkOrder::JENIS, 'rows' => $rows,
            'total' => ['contract' => $rows->sum('contract'), 'progress' => $rows->sum('progress'), 'retention' => $rows->sum('retention'), 'remaining' => $rows->sum('remaining')],
        ]);
    }

    /** Kartu Hutang Supplier - SPK: per-SPK hutang (net termin) vs terbayar. */
    public function kartuHutang(Request $request): Response
    {
        abort_unless($request->user()?->canModule('contractor'), 403);
        $f = $this->filters($request);

        $rows = BsWorkOrder::with(['supplier:id,name', 'project:id,code,name', 'progress:id,work_order_id,net_payable,status'])
            ->when($f['project_id'], fn ($q, $id) => $q->where('project_id', $id))
            ->when($f['jenis'], fn ($q, $j) => $q->where('jenis', $j))
            ->orderByDesc('spk_date')->get()
            ->map(function ($w) {
                $billed = (float) $w->progress->sum('net_payable');
                $paid   = (float) $w->progress->where('status', 'paid')->sum('net_payable');

                return [
                    'spk_number' => $w->spk_number, 'supplier' => $w->supplier?->name, 'project' => $w->project?->name,
                    'date' => $w->spk_date?->format('Y-m-d'), 'jenis' => $w->jenis,
                    'billed' => $billed, 'paid' => $paid, 'outstanding' => round($billed - $paid, 2),
                ];
            })->values();

        return Inertia::render('BusinessSupport/Contractor/Query/KartuHutang', [
            'filters' => $f, 'projects' => $this->projectOpts(), 'jenisOpts' => BsWorkOrder::JENIS, 'rows' => $rows,
            'total' => ['billed' => $rows->sum('billed'), 'paid' => $rows->sum('paid'), 'outstanding' => $rows->sum('outstanding')],
        ]);
    }

    /** Laporan Pemakaian Barang. */
    public function pemakaian(Request $request): Response
    {
        abort_unless($request->user()?->canModule('contractor'), 403);
        $f = $this->filters($request);

        $rows = BsWorkOrderMaterial::with(['workOrder:id,spk_number,title,project_id,jenis', 'workOrder.project:id,name', 'inventoryAccount:id,code,name'])
            ->whereHas('workOrder', function ($q) use ($f) {
                $q->when($f['project_id'], fn ($qq, $id) => $qq->where('project_id', $id))
                  ->when($f['jenis'], fn ($qq, $j) => $qq->where('jenis', $j));
            })
            ->orderByDesc('usage_date')->get()
            ->map(fn ($m) => [
                'date' => $m->usage_date?->format('Y-m-d'), 'spk' => $m->workOrder?->spk_number,
                'project' => $m->workOrder?->project?->name, 'description' => $m->description,
                'account' => $m->inventoryAccount?->code.' - '.$m->inventoryAccount?->name,
                'amount' => (float) $m->amount, 'returned' => (float) $m->returned_amount, 'status' => $m->status,
            ])->values();

        return Inertia::render('BusinessSupport/Contractor/Query/Pemakaian', [
            'filters' => $f, 'projects' => $this->projectOpts(), 'jenisOpts' => BsWorkOrder::JENIS, 'rows' => $rows,
            'total' => ['amount' => $rows->sum('amount'), 'returned' => $rows->sum('returned')],
        ]);
    }

    /** Summary SPK — agregasi per project & jenis. */
    public function summary(Request $request): Response
    {
        abort_unless($request->user()?->canModule('contractor'), 403);
        $f = $this->filters($request);

        $rows = BsWorkOrder::with('project:id,name')
            ->when($f['project_id'], fn ($q, $id) => $q->where('project_id', $id))
            ->when($f['jenis'], fn ($q, $j) => $q->where('jenis', $j))
            ->get()
            ->groupBy(fn ($w) => ($w->project?->name ?? 'Tanpa Project').' | '.($w->jenis ?? '-'))
            ->map(fn ($grp, $key) => [
                'group' => $key, 'count' => $grp->count(),
                'contract' => (float) $grp->sum('contract_value'), 'progress' => (float) $grp->sum('progress_value'),
                'retention' => (float) $grp->sum('retention_held'),
            ])->values();

        return Inertia::render('BusinessSupport/Contractor/Query/Summary', [
            'filters' => $f, 'projects' => $this->projectOpts(), 'jenisOpts' => BsWorkOrder::JENIS, 'rows' => $rows,
            'total' => ['contract' => $rows->sum('contract'), 'progress' => $rows->sum('progress'), 'retention' => $rows->sum('retention')],
        ]);
    }
}
