<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Contractor;

use App\Domain\BusinessSupport\Models\BsWorkOrder;
use App\Domain\BusinessSupport\Models\BsWorkOrderProgress;
use App\Domain\BusinessSupport\Services\ContractorService;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Kontrol Progress Lapangan (KPL / opname) — mencatat progress lapangan terhadap SPK.
 * Output = termin progress (jurnal Dr CIP+PPN / Cr Hutang+Retensi) via ContractorService::addProgress.
 */
class KplController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->canModule('contractor'), 403);

        $rows = BsWorkOrderProgress::query()
            ->with(['workOrder:id,spk_number,title,supplier_id', 'workOrder.supplier:id,name'])
            ->when($request->input('search'), fn ($q, $s) => $q->where(fn ($w) => $w->where('kpl_number', 'like', "%{$s}%")->orWhere('description', 'like', "%{$s}%")))
            ->latest('progress_date')->latest('id')->paginate(50)->withQueryString();

        return Inertia::render('BusinessSupport/Contractor/Kpl/Index', [
            'rows'    => $rows,
            'filters' => $request->only('search'),
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()?->canModule('contractor'), 403);

        $spks = BsWorkOrder::with('supplier:id,name')->where('status', 'active')
            ->orderByDesc('spk_date')->orderByDesc('id')->limit(500)->get()
            ->map(fn ($w) => [
                'id' => $w->id, 'spk_number' => $w->spk_number, 'title' => $w->title,
                'supplier' => $w->supplier?->name, 'remaining' => (float) $w->remaining_value,
                'retention_pct' => (float) $w->retention_pct, 'ppn_pct' => (float) $w->ppn_pct,
            ])->values();

        return Inertia::render('BusinessSupport/Contractor/Kpl/Create', ['spks' => $spks]);
    }

    public function store(Request $request, ContractorService $svc): RedirectResponse
    {
        abort_unless($request->user()?->canModule('contractor'), 403);
        $v = $request->validate([
            'work_order_id'  => ['required', 'integer', 'exists:bs_work_orders,id'],
            'progress_date'  => ['required', 'date'],
            'description'    => ['required', 'string', 'max:200'],
            'progress_value' => ['required', 'numeric', 'min:0.01'],
            'releaser'       => ['nullable', 'string', 'max:100'],
            'pic'            => ['nullable', 'string', 'max:100'],
            'no_manual'      => ['nullable', 'string', 'max:50'],
        ]);

        $wo = BsWorkOrder::findOrFail($v['work_order_id']);
        try {
            $svc->addProgress($wo, $v, $request->user()->id);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('bs.spk.kpl.index')->with('success', 'Kontrol Progress Lapangan tercatat — jurnal termin otomatis.');
    }
}
