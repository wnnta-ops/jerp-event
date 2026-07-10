<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Contractor;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsCostCenter;
use App\Domain\BusinessSupport\Models\BsProject;
use App\Domain\BusinessSupport\Models\BsSupplier;
use App\Domain\BusinessSupport\Models\BsWorkOrder;
use App\Domain\BusinessSupport\Models\BsWorkOrderProgress;
use App\Domain\BusinessSupport\Services\ContractorService;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ContractorController extends Controller
{
    /** Hub modul Contractor Management (mirror menu eProperty BO /PLAN/). */
    public function hub(Request $request): Response
    {
        abort_unless($request->user()?->canModule('contractor'), 403);

        return Inertia::render('BusinessSupport/Contractor/Hub', [
            'summary' => [
                'contract'   => (float) BsWorkOrder::sum('contract_value'),
                'progress'   => (float) BsWorkOrder::sum('progress_value'),
                'retention'  => (float) BsWorkOrder::sum('retention_held'),
                'active'     => BsWorkOrder::where('status', 'active')->count(),
                'spk'        => BsWorkOrder::count(),
                'mut_pending'=> \App\Domain\BusinessSupport\Models\BsWorkOrderProgressMutation::where('status', 'baru')->count(),
            ],
        ]);
    }


    /** Form picker: Addendum / Adjust PPN / Pembatalan — pilih SPK aktif lalu proses. */
    public function pickerForm(Request $request, string $action): Response
    {
        abort_unless($request->user()?->canModule('contractor'), 403);
        $page = ['addendum' => 'Addendum', 'adjust-ppn' => 'AdjustPpn', 'cancel' => 'Cancel'][$action] ?? abort(404);

        $spks = BsWorkOrder::with(['supplier:id,name', 'project:id,code,name'])
            ->where('status', 'active')->orderByDesc('spk_date')->orderByDesc('id')->limit(500)
            ->get()->map(fn ($w) => [
                'id' => $w->id, 'spk_number' => $w->spk_number, 'title' => $w->title,
                'supplier' => $w->supplier?->name, 'contract_value' => (float) $w->contract_value,
                'ppn_pct' => (float) $w->ppn_pct, 'progress_value' => (float) $w->progress_value,
            ])->values();

        return Inertia::render("BusinessSupport/Contractor/{$page}", ['spks' => $spks]);
    }

    public function index(Request $request): Response
    {
        abort_unless($request->user()?->canModule('contractor'), 403);

        $rows = BsWorkOrder::query()
            ->with(['supplier:id,code,name', 'project:id,code,name'])
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->input('jenis'), fn ($q, $v) => $q->where('jenis', $v))
            ->when($request->input('search'), fn ($q, $s) => $q->where(fn ($w) => $w->where('spk_number', 'like', "%{$s}%")->orWhere('title', 'like', "%{$s}%")))
            ->latest('spk_date')->latest('id')
            ->paginate(50)->withQueryString();

        return Inertia::render('BusinessSupport/Contractor/Index', [
            'workOrders' => $rows,
            'filters'    => $request->only(['status', 'search', 'jenis']),
            'jenisOpts'  => BsWorkOrder::JENIS,
            'summary'    => [
                'contract'  => (float) BsWorkOrder::sum('contract_value'),
                'progress'  => (float) BsWorkOrder::sum('progress_value'),
                'retention' => (float) BsWorkOrder::sum('retention_held'),
                'active'    => BsWorkOrder::where('status', 'active')->count(),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()?->canModule('contractor'), 403);

        return Inertia::render('BusinessSupport/Contractor/Form', [
            'projects'     => BsProject::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'jenisOpts'    => BsWorkOrder::JENIS,
            'suppliers'    => BsSupplier::where('is_active', true)->orderBy('name')->get(['id', 'code', 'name', 'category']),
            'costCenters'  => BsCostCenter::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'constructionAccounts' => BsAccount::where('is_active', true)->where('is_postable', true)
                ->where(fn ($q) => $q->where('code', 'like', '10104%')->orWhere('type', 'expense'))
                ->orderBy('code')->get(['id', 'code', 'name']),
            'defaultConstruction' => BsAccount::where('code', config('business_support.contractor.construction'))->value('id'),
        ]);
    }

    public function store(Request $request, ContractorService $svc): RedirectResponse
    {
        abort_unless($request->user()?->canModule('contractor'), 403);

        $v = $request->validate([
            'spk_date'                => ['required', 'date'],
            'no_manual'               => ['required', 'string', 'max:50'],
            'est_start'               => ['required', 'date'],
            'est_end'                 => ['required', 'date', 'after_or_equal:est_start'],
            'project_id'              => ['required', 'integer', 'exists:bs_projects,id'],
            'jenis'                   => ['required', 'in:bangunan,sarana_prasarana,lain_lain'],
            'supplier_id'             => ['required', 'integer', 'exists:bs_suppliers,id'],
            'cost_center_id'          => ['required', 'integer', 'exists:bs_cost_centers,id'],
            'construction_account_id' => ['required', 'integer', 'exists:bs_accounts,id'],
            'title'                   => ['required', 'string', 'max:200'],
            'releaser'                => ['nullable', 'string', 'max:100'],
            'pic_project'             => ['nullable', 'string', 'max:100'],
            'is_pkp'                  => ['nullable', 'boolean'],
            'contract_value'          => ['required', 'numeric', 'min:0.01'],
            'nominal_profit'          => ['nullable', 'numeric', 'min:0'],
            'retention_pct'           => ['required', 'numeric', 'min:0', 'max:100'],
            'advance_pct'             => ['required', 'numeric', 'min:0', 'max:100'],
            'ppn_pct'                 => ['required', 'numeric', 'min:0', 'max:100'],
            'notes'                   => ['nullable', 'string', 'max:255'],
            'items'                   => ['nullable', 'array'],
            'items.*.description'     => ['nullable', 'string', 'max:200'],
            'items.*.unit'            => ['nullable', 'string', 'max:20'],
            'items.*.qty'             => ['nullable', 'numeric', 'min:0'],
            'items.*.unit_price'      => ['nullable', 'numeric', 'min:0'],
            'terms'                   => ['nullable', 'array'],
            'terms.*.description'     => ['nullable', 'string', 'max:100'],
            'terms.*.percentage'      => ['nullable', 'numeric', 'min:0', 'max:100'],
            'terms.*.amount'          => ['nullable', 'numeric', 'min:0'],
            'terms.*.due_date'        => ['nullable', 'date'],
        ]);

        $wo = $svc->createSpk($v, $request->user()->id);

        return redirect()->route('bs.spk.show', $wo)->with('success', "SPK {$wo->spk_number} dibuat.");
    }

    public function show(Request $request, BsWorkOrder $workOrder): Response
    {
        abort_unless($request->user()?->canModule('contractor'), 403);

        return Inertia::render('BusinessSupport/Contractor/Show', [
            'spk'          => $workOrder->load([
                'supplier:id,code,name', 'costCenter:id,code,name', 'project:id,code,name',
                'items' => fn ($q) => $q->orderBy('seq')->orderBy('id'),
                'terms' => fn ($q) => $q->orderBy('seq')->orderBy('id'),
                'progress' => fn ($q) => $q->orderBy('progress_date')->orderBy('id'),
                'addendums' => fn ($q) => $q->orderBy('addendum_date')->orderBy('id'),
                'materials' => fn ($q) => $q->orderBy('usage_date')->orderBy('id'),
                'materialReturns' => fn ($q) => $q->orderBy('return_date')->orderBy('id'),
                'progressMutations' => fn ($q) => $q->with(['toAccount:id,code,name', 'toCostCenter:id,code,name'])->orderBy('mutation_date')->orderBy('id'),
            ]),
            'remaining'    => (float) $workOrder->remaining_value,
            'cashAccounts' => BsAccount::where('is_active', true)->where('is_postable', true)->where('code', 'like', '101010%')->orderBy('code')->get(['id', 'code', 'name']),
            'inventoryAccounts' => BsAccount::where('is_active', true)->where('is_postable', true)
                ->where(fn ($q) => $q->where('code', 'like', '10104%')->orWhere('code', 'like', '10103%'))
                ->orderBy('code')->get(['id', 'code', 'name']),
            'costCenters'      => BsCostCenter::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'defaultCash'      => BsAccount::where('code', config('business_support.gl_accounts.cash'))->value('id'),
            'defaultInventory' => BsAccount::where('code', config('business_support.contractor.material_inventory'))->value('id'),
        ]);
    }

    /**
     * Cetak SPK — halaman dokumen SPK yang bisa diedit per-pasal lalu dicetak (window.print).
     * Semua field & pasal ter-prefill dari data SPK; user boleh mengubah sebagian saja.
     */
    public function printForm(Request $request, BsWorkOrder $workOrder): Response
    {
        abort_unless($request->user()?->canModule('contractor'), 403);

        $workOrder->load([
            'supplier:id,code,name,address,city,npwp,contact_person,phone,bank_name,bank_account_no,bank_account_name',
            'project:id,code,name', 'costCenter:id,code,name',
            'items' => fn ($q) => $q->orderBy('seq')->orderBy('id'),
            'terms' => fn ($q) => $q->orderBy('seq')->orderBy('id'),
        ]);

        $draft = \App\Domain\BusinessSupport\Models\BsSpkDraft::query()->first();

        return Inertia::render('BusinessSupport/Contractor/Print', [
            'spk'       => $workOrder,
            'company'   => config('business_support.company'),
            'jenisOpts' => BsWorkOrder::JENIS,
            'draft'     => $draft?->only(['judul', 'subjudul', 'tempat', 'pembukaan', 'pasals']),
            'saved'     => $workOrder->print_payload,
        ]);
    }

    /** Simpan editan dokumen Cetak SPK per-SPK (dari tombol "Simpan" di Cetak SPK). */
    public function printSave(Request $request, BsWorkOrder $workOrder): RedirectResponse
    {
        abort_unless($request->user()?->canModule('contractor'), 403);

        $payload = $request->validate([
            'payload' => ['required', 'array'],
        ])['payload'];

        $workOrder->update(['print_payload' => $payload]);

        return back()->with('success', 'Dokumen SPK tersimpan.');
    }

    public function mutateProgress(Request $request, BsWorkOrder $workOrder, ContractorService $svc): RedirectResponse
    {
        abort_unless($request->user()?->canModule('contractor'), 403);

        $v = $request->validate([
            'mutation_date'      => ['required', 'date'],
            'amount'             => ['required', 'numeric', 'min:0.01'],
            'to_account_id'      => ['required', 'integer', 'exists:bs_accounts,id'],
            'to_cost_center_id'  => ['nullable', 'integer', 'exists:bs_cost_centers,id'],
            'note'               => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $svc->mutateProgress($workOrder, $v, $request->user()->id);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('bs.spk.show', $workOrder)->with('success', 'Mutasi progress tercatat — jurnal reklasifikasi CIP (Dr tujuan / Cr asal).');
    }

    public function addendum(Request $request, BsWorkOrder $workOrder, ContractorService $svc): RedirectResponse
    {
        abort_unless($request->user()?->canModule('contractor'), 403);

        $v = $request->validate([
            'addendum_date' => ['required', 'date'],
            'value_delta'   => ['required', 'numeric'],
            'note'          => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $svc->addendum($workOrder, $v, $request->user()->id);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('bs.spk.show', $workOrder)->with('success', 'Addendum nilai kontrak tercatat.');
    }

    public function adjustPpn(Request $request, BsWorkOrder $workOrder, ContractorService $svc): RedirectResponse
    {
        abort_unless($request->user()?->canModule('contractor'), 403);

        $v = $request->validate([
            'addendum_date' => ['required', 'date'],
            'new_ppn_pct'   => ['required', 'numeric', 'min:0', 'max:100'],
            'note'          => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $svc->adjustPpn($workOrder, $v, $request->user()->id);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('bs.spk.show', $workOrder)->with('success', 'PPN SPK disesuaikan untuk progress berikutnya.');
    }

    public function cancel(Request $request, BsWorkOrder $workOrder, ContractorService $svc): RedirectResponse
    {
        abort_unless($request->user()?->canModule('contractor'), 403);

        $v = $request->validate([
            'cancel_date' => ['required', 'date'],
            'reason'      => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $svc->cancel($workOrder, $v, $request->user()->id);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('bs.spk.show', $workOrder)->with('success', "SPK {$workOrder->spk_number} dibatalkan.");
    }

    public function useMaterial(Request $request, BsWorkOrder $workOrder, ContractorService $svc): RedirectResponse
    {
        abort_unless($request->user()?->canModule('contractor'), 403);

        $v = $request->validate([
            'usage_date'           => ['required', 'date'],
            'description'          => ['required', 'string', 'max:200'],
            'amount'               => ['required', 'numeric', 'min:0.01'],
            'inventory_account_id' => ['required', 'integer', 'exists:bs_accounts,id'],
        ]);

        try {
            $svc->useMaterial($workOrder, $v, $request->user()->id);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('bs.spk.show', $workOrder)->with('success', 'Pemakaian barang tercatat — jurnal otomatis (Dr CIP / Cr Persediaan).');
    }

    public function addProgress(Request $request, BsWorkOrder $workOrder, ContractorService $svc): RedirectResponse
    {
        abort_unless($request->user()?->canModule('contractor'), 403);

        $v = $request->validate([
            'progress_date'  => ['required', 'date'],
            'description'    => ['required', 'string', 'max:200'],
            'progress_value' => ['required', 'numeric', 'min:0.01'],
        ]);

        try {
            $svc->addProgress($workOrder, $v, $request->user()->id);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('bs.spk.show', $workOrder)->with('success', 'Progress termin tercatat — jurnal otomatis (Dr CIP+PPN / Cr Hutang+Retensi).');
    }

    public function payProgress(Request $request, BsWorkOrderProgress $progress, ContractorService $svc): RedirectResponse
    {
        abort_unless($request->user()?->canModule('contractor'), 403);

        $v = $request->validate([
            'payment_date'    => ['required', 'date'],
            'cash_account_id' => ['required', 'integer', 'exists:bs_accounts,id'],
        ]);

        try {
            $svc->payProgress($progress, $v, $request->user()->id);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('bs.spk.show', $progress->work_order_id)->with('success', 'Termin dibayar — jurnal otomatis (Dr Hutang / Cr Kas).');
    }

    public function releaseRetention(Request $request, BsWorkOrder $workOrder, ContractorService $svc): RedirectResponse
    {
        abort_unless($request->user()?->canModule('contractor'), 403);

        $v = $request->validate([
            'settled_date'    => ['required', 'date'],
            'cash_account_id' => ['required', 'integer', 'exists:bs_accounts,id'],
        ]);

        try {
            $svc->releaseRetention($workOrder, $v, $request->user()->id);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('bs.spk.show', $workOrder)->with('success', 'Retensi dicairkan & SPK ditutup — jurnal otomatis (Dr Hutang Retensi / Cr Kas).');
    }
}
