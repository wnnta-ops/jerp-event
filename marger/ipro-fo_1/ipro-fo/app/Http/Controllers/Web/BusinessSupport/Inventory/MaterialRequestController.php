<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Inventory;

use App\Domain\BusinessSupport\Models\BsCostCenter;
use App\Domain\BusinessSupport\Models\BsItem;
use App\Domain\BusinessSupport\Models\BsMaterialRequest;
use App\Domain\BusinessSupport\Services\StockService;
use App\Domain\Foundation\Tenant\Models\Project;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class MaterialRequestController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('bs.fpb.view'), 403);

        $status = (string) $request->input('status', '');

        $rows = BsMaterialRequest::query()
            ->with('costCenter:id,code,name')
            ->withCount('lines')
            ->when($status !== '', fn ($q) => $q->where('status', $status))
            // FPB yang SUDAH DISETUJUI pindah ke Purchase Order — tindak lanjutnya di sana,
            // bukan di sini. Tetap bisa dilihat bila statusnya dipilih sendiri lewat filter,
            // supaya riwayatnya tidak jadi tidak terjangkau.
            ->when($status !== 'approved', fn ($q) => $q->where('status', '!=', 'approved'))
            ->latest('request_date')->latest('id')
            ->paginate(25)->withQueryString();

        return Inertia::render('BusinessSupport/Inventory/MaterialRequests/Index', [
            'requests' => $rows,
            'filters'  => $request->only(['status']),
            // Berapa yang sudah berpindah, supaya operator tahu ke mana perginya.
            'approvedCount' => BsMaterialRequest::where('status', 'approved')->count(),
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()?->can('bs.fpb.create'), 403);

        return Inertia::render('BusinessSupport/Inventory/MaterialRequests/Form', $this->formData());
    }

    public function store(Request $request, StockService $stock): RedirectResponse
    {
        abort_unless($request->user()?->can('bs.fpb.create'), 403);

        $v = $request->validate([
            'project_id'     => ['nullable', 'integer', 'exists:projects,id'],
            'cost_center_id' => ['nullable', 'integer', 'exists:bs_cost_centers,id'],
            'request_date'   => ['required', 'date'],
            'needed_date'    => ['nullable', 'date'],
            'department'     => ['nullable', 'string', 'max:150'],
            'notes'          => ['nullable', 'string', 'max:500'],
            'lines'              => ['required', 'array', 'min:1'],
            'lines.*.item_id'    => ['required', 'integer', 'exists:bs_items,id'],
            'lines.*.qty'        => ['required', 'numeric', 'min:0.01'],
            'lines.*.kind'       => ['nullable', 'in:expense,asset'],
            'lines.*.spec'       => ['nullable', 'string', 'max:200'],
        ]);

        $mr = DB::transaction(function () use ($v, $stock, $request) {
            $mr = BsMaterialRequest::create([
                'project_id'     => $v['project_id'] ?? null,
                'cost_center_id' => $v['cost_center_id'] ?? null,
                'mr_number'      => $stock->nextNumber('FPB', BsMaterialRequest::class, 'request_date'),
                'request_date'   => $v['request_date'],
                'needed_date'    => $v['needed_date'] ?? null,
                'department'     => $v['department'] ?? null,
                'status'         => 'draft',
                'notes'          => $v['notes'] ?? null,
                'created_by'     => $request->user()->id,
            ]);
            foreach ($v['lines'] as $l) {
                $mr->lines()->create(['item_id' => $l['item_id'], 'qty' => $l['qty'], 'kind' => $l['kind'] ?? 'expense', 'spec' => $l['spec'] ?? null]);
            }
            return $mr;
        });

        return redirect()->route('bs.fpb.show', $mr)->with('success', "FPB {$mr->mr_number} dibuat.");
    }

    public function show(Request $request, BsMaterialRequest $fpb): Response
    {
        abort_unless($request->user()?->can('bs.fpb.view'), 403);

        return Inertia::render('BusinessSupport/Inventory/MaterialRequests/Show', [
            'request' => $fpb->load(['lines.item:id,code,name', 'costCenter:id,code,name']),
        ]);
    }

    public function approve(Request $request, BsMaterialRequest $fpb): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.approval.act'), 403);
        if ($fpb->status !== 'draft') {
            return back()->with('error', 'FPB sudah diproses.');
        }
        $fpb->update(['status' => 'approved', 'approved_at' => now(), 'approved_by' => $request->user()->id]);

        return back()->with('success', "FPB {$fpb->mr_number} disetujui.");
    }

    private function formData(): array
    {
        return [
            'projects'     => Project::orderBy('name')->get(['id', 'name']),
            'costCenters'  => BsCostCenter::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'items'        => BsItem::where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']),
        ];
    }
}
