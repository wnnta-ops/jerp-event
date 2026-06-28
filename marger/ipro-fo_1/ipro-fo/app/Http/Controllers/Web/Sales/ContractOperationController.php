<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Sales;

use App\Domain\Foundation\Tenant\Models\Project;
use App\Domain\Master\Customer\Models\Customer;
use App\Domain\Master\Unit\Models\Unit;
use App\Domain\Sales\Contract\Models\ContractOperation;
use App\Domain\Sales\Contract\Services\ContractOperationService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Operasi Kontrak (eProperty SALES → Kontrak): Adjustment, PPN, Tagihan Custom,
 * Pengalihan Hak, Pindah Unit, Pembatalan.
 */
class ContractOperationController extends Controller
{
    /** slug rute → tipe operasi service. */
    private const SLUGS = [
        'adjustment'  => 'adjustment',
        'tagihan'     => 'tagihan_custom',
        'ganti-nama'  => 'ganti_nama',
        'pindah-unit' => 'pindah_unit',
        'batal'       => 'batal',
    ];

    public function __construct(private readonly ContractOperationService $service) {}

    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('sales.contract.view'), 403);

        return Inertia::render('Sales/ContractOps/Index', [
            'recent' => ContractOperation::query()->with('contract:id,contract_number', 'processor:id,name')
                ->latest('id')->limit(40)->get()
                ->map(fn ($o) => [
                    'id' => $o->id, 'operation_no' => $o->operation_no,
                    'type' => $o->type, 'type_label' => ContractOperation::TYPES[$o->type] ?? $o->type,
                    'contract' => $o->contract?->contract_number, 'amount' => (float) $o->amount,
                    'reason' => $o->reason, 'by' => $o->processor?->name, 'at' => $o->processed_at?->toDateTimeString(),
                ]),
            'types' => ContractOperation::TYPES,
        ]);
    }

    /** Cari kontrak (AJAX) untuk langkah "No. Kontrak → Next". */
    public function find(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can('sales.contract.view'), 403);
        $contract = $this->service->findByNumber((string) $request->input('number'));
        if (! $contract) {
            return response()->json(['found' => false, 'message' => 'Kontrak tidak ditemukan.'], 404);
        }

        return response()->json(['found' => true, 'summary' => $this->service->summary($contract)]);
    }

    /** Halaman operasi single-kontrak (finder + form per tipe). */
    public function operation(Request $request, string $type): Response
    {
        abort_unless($request->user()?->can('sales.contract.view'), 403);
        abort_unless(isset(self::SLUGS[$type]), 404);

        return Inertia::render('Sales/ContractOps/Operation', [
            'slug'      => $type,
            'opType'    => self::SLUGS[$type],
            'title'     => ContractOperation::TYPES[self::SLUGS[$type]] ?? 'Operasi Kontrak',
            'customers' => $type === 'ganti-nama'
                ? Customer::orderBy('full_name')->limit(1000)->get(['id', 'code', 'full_name'])
                : [],
            'units'     => $type === 'pindah-unit'
                ? Unit::where('status', 'available')->orderBy('unit_code')->limit(1000)->get(['id', 'unit_code', 'current_price'])
                : [],
        ]);
    }

    public function apply(Request $request, string $type): RedirectResponse
    {
        abort_unless($request->user()?->can('sales.contract.update'), 403);
        abort_unless(isset(self::SLUGS[$type]), 404);
        $contract = $this->service->findByNumber((string) $request->input('contract_number'));
        abort_unless($contract !== null, 422, 'Kontrak tidak ditemukan.');
        $uid = $request->user()->id;

        match (self::SLUGS[$type]) {
            'adjustment' => $this->service->adjust($contract, $request->validate([
                'base_price'      => ['required', 'numeric', 'min:0'],
                'discount_amount' => ['nullable', 'numeric', 'min:0'],
                'reason'          => ['nullable', 'string', 'max:500'],
            ]), $uid),
            'tagihan_custom' => $this->service->tagihanCustom($contract, $request->validate([
                'label'        => ['required', 'string', 'max:150'],
                'due_date'     => ['required', 'date'],
                'amount'       => ['required', 'numeric', 'min:1'],
                'payment_type' => ['nullable', 'string', 'max:5'],
            ]), $uid),
            'ganti_nama' => $this->service->gantiNama($contract,
                (int) $request->validate(['new_customer_id' => ['required', 'integer', 'exists:customers,id']])['new_customer_id'],
                $request->input('reason'), $uid),
            'pindah_unit' => $this->service->pindahUnit($contract,
                (int) $request->validate(['new_unit_id' => ['required', 'integer', 'exists:units,id']])['new_unit_id'],
                $request->input('reason'), $uid),
            'batal' => $this->service->batal($contract,
                (string) $request->validate(['reason' => ['required', 'string', 'max:500']])['reason'],
                (float) $request->input('penalty', 0), (float) $request->input('refund', 0), $uid),
            default => abort(404),
        };

        return redirect()->route('sales.kontrak-ops.index')->with('success', 'Operasi kontrak berhasil diterapkan.');
    }

    // ── Adjustment PPN (bulk) ──
    public function ppn(Request $request): Response
    {
        abort_unless($request->user()?->can('sales.contract.view'), 403);

        return Inertia::render('Sales/ContractOps/Ppn', [
            'projects' => Project::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function ppnPreview(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can('sales.contract.view'), 403);
        $v = $request->validate([
            'project_id' => ['required', 'integer', 'exists:projects,id'],
            'old_rate'   => ['required', 'numeric', 'min:0', 'max:100'],
            'new_rate'   => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        return response()->json(['rows' => $this->service->ppnPreview((int) $v['project_id'], (float) $v['old_rate'], (float) $v['new_rate'])]);
    }

    public function ppnApply(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('sales.contract.update'), 403);
        $v = $request->validate([
            'contract_ids'   => ['required', 'array', 'min:1'],
            'contract_ids.*' => ['integer', 'exists:contracts,id'],
            'old_rate'       => ['required', 'numeric', 'min:0', 'max:100'],
            'new_rate'       => ['required', 'numeric', 'min:0', 'max:100'],
        ]);
        $n = $this->service->applyPpn($v['contract_ids'], (float) $v['old_rate'], (float) $v['new_rate'], $request->user()->id);

        return redirect()->route('sales.kontrak-ops.index')->with('success', "Tarif PPN diterapkan ke {$n} kontrak.");
    }
}
