<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Contractor;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsWorkOrder;
use App\Domain\BusinessSupport\Models\BsWorkOrderMaterial;
use App\Domain\BusinessSupport\Services\ContractorService;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pemakaian Barang SBO: Pemakaian Barang + Close SBO + Pengembalian Sisa Pemakaian.
 */
class MaterialController extends Controller
{
    /** Master Pemakaian Barang SBO. */
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->canModule('contractor'), 403);

        $rows = BsWorkOrderMaterial::query()
            ->with(['workOrder:id,spk_number,title', 'inventoryAccount:id,code,name'])
            ->latest('usage_date')->latest('id')->paginate(50)->withQueryString();

        return Inertia::render('BusinessSupport/Contractor/Material/Index', [
            'rows'    => $rows,
            'summary' => ['total' => (float) BsWorkOrderMaterial::sum('amount'), 'returned' => (float) BsWorkOrderMaterial::sum('returned_amount')],
        ]);
    }

    /** Registrasi Pemakaian Barang. */
    public function create(Request $request): Response
    {
        abort_unless($request->user()?->canModule('contractor'), 403);

        return Inertia::render('BusinessSupport/Contractor/Material/Create', [
            'spks'              => $this->activeSpks(),
            'inventoryAccounts' => $this->inventoryAccounts(),
            'defaultInventory'  => BsAccount::where('code', config('business_support.contractor.material_inventory'))->value('id'),
        ]);
    }

    public function store(Request $request, ContractorService $svc): RedirectResponse
    {
        abort_unless($request->user()?->canModule('contractor'), 403);
        $v = $request->validate([
            'work_order_id'        => ['required', 'integer', 'exists:bs_work_orders,id'],
            'usage_date'           => ['required', 'date'],
            'description'          => ['required', 'string', 'max:200'],
            'amount'               => ['required', 'numeric', 'min:0.01'],
            'inventory_account_id' => ['required', 'integer', 'exists:bs_accounts,id'],
        ]);
        $wo = BsWorkOrder::findOrFail($v['work_order_id']);
        try {
            $svc->useMaterial($wo, $v, $request->user()->id);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('bs.spk.material.index')->with('success', 'Pemakaian barang tercatat — jurnal otomatis (Dr CIP / Cr Persediaan).');
    }

    /** Close SBO — pilih SPK dengan pemakaian masih open lalu tutup. */
    public function closeForm(Request $request): Response
    {
        abort_unless($request->user()?->canModule('contractor'), 403);

        $spks = BsWorkOrder::withCount(['materials as open_materials' => fn ($q) => $q->where('status', 'open')])
            ->whereHas('materials', fn ($q) => $q->where('status', 'open'))
            ->with('supplier:id,name')->orderByDesc('spk_date')->get()
            ->map(fn ($w) => [
                'id' => $w->id, 'spk_number' => $w->spk_number, 'title' => $w->title,
                'supplier' => $w->supplier?->name, 'open_materials' => $w->open_materials,
                'material_used' => (float) $w->material_used,
            ])->values();

        return Inertia::render('BusinessSupport/Contractor/Material/Close', ['spks' => $spks]);
    }

    public function close(Request $request, ContractorService $svc): RedirectResponse
    {
        abort_unless($request->user()?->canModule('contractor'), 403);
        $v = $request->validate([
            'work_order_id' => ['required', 'integer', 'exists:bs_work_orders,id'],
            'close_date'    => ['required', 'date'],
        ]);
        $wo = BsWorkOrder::findOrFail($v['work_order_id']);
        $n  = $svc->closeSbo($wo, $v['close_date']);

        return redirect()->route('bs.spk.material.index')->with('success', "Close SBO: {$n} pemakaian barang ditutup untuk SPK {$wo->spk_number}.");
    }

    /** Pengembalian Sisa Pemakaian. */
    public function returnForm(Request $request): Response
    {
        abort_unless($request->user()?->canModule('contractor'), 403);

        $spks = BsWorkOrder::with('supplier:id,name')->where('material_used', '>', 0)
            ->orderByDesc('spk_date')->get()
            ->map(fn ($w) => [
                'id' => $w->id, 'spk_number' => $w->spk_number, 'title' => $w->title,
                'supplier' => $w->supplier?->name, 'material_used' => (float) $w->material_used,
            ])->values();

        return Inertia::render('BusinessSupport/Contractor/Material/Return', [
            'spks'              => $spks,
            'inventoryAccounts' => $this->inventoryAccounts(),
            'defaultInventory'  => BsAccount::where('code', config('business_support.contractor.material_inventory'))->value('id'),
        ]);
    }

    public function returnStore(Request $request, ContractorService $svc): RedirectResponse
    {
        abort_unless($request->user()?->canModule('contractor'), 403);
        $v = $request->validate([
            'work_order_id'        => ['required', 'integer', 'exists:bs_work_orders,id'],
            'return_date'          => ['required', 'date'],
            'description'          => ['nullable', 'string', 'max:200'],
            'amount'               => ['required', 'numeric', 'min:0.01'],
            'inventory_account_id' => ['required', 'integer', 'exists:bs_accounts,id'],
        ]);
        $wo = BsWorkOrder::findOrFail($v['work_order_id']);
        try {
            $svc->returnMaterial($wo, $v, $request->user()->id);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('bs.spk.material.index')->with('success', 'Pengembalian sisa pemakaian tercatat — jurnal otomatis (Dr Persediaan / Cr CIP).');
    }

    private function activeSpks()
    {
        return BsWorkOrder::with('supplier:id,name')->where('status', 'active')
            ->orderByDesc('spk_date')->orderByDesc('id')->limit(500)->get()
            ->map(fn ($w) => ['id' => $w->id, 'spk_number' => $w->spk_number, 'title' => $w->title, 'supplier' => $w->supplier?->name])->values();
    }

    private function inventoryAccounts()
    {
        return BsAccount::where('is_active', true)->where('is_postable', true)
            ->where(fn ($q) => $q->where('code', 'like', '10104%')->orWhere('code', 'like', '10103%'))
            ->orderBy('code')->get(['id', 'code', 'name']);
    }
}
