<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Inventory;

use App\Domain\BusinessSupport\Models\BsItem;
use App\Domain\BusinessSupport\Models\BsMaterialRequest;
use App\Domain\BusinessSupport\Models\BsStockTransaction;
use App\Domain\BusinessSupport\Models\BsWarehouse;
use App\Domain\BusinessSupport\Services\StockService;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class GoodsIssueController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->canModule('bs'), 403);

        $rows = BsStockTransaction::query()
            ->where('trx_type', 'issue')
            ->with('warehouse:id,code,name')
            ->withCount('lines')
            ->latest('trx_date')->latest('id')
            ->paginate(50);

        return Inertia::render('BusinessSupport/Inventory/GoodsIssues/Index', ['issues' => $rows]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()?->canModule('bs'), 403);

        return Inertia::render('BusinessSupport/Inventory/GoodsIssues/Form', [
            'warehouses' => BsWarehouse::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'items'      => BsItem::where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']),
            'materialRequests' => BsMaterialRequest::where('status', 'approved')->latest('request_date')->limit(50)->get(['id', 'mr_number']),
        ]);
    }

    public function store(Request $request, StockService $stock): RedirectResponse
    {
        abort_unless($request->user()?->canModule('bs'), 403);

        $v = $request->validate([
            'warehouse_id'        => ['required', 'integer', 'exists:bs_warehouses,id'],
            'material_request_id' => ['nullable', 'integer', 'exists:bs_material_requests,id'],
            'trx_date'            => ['required', 'date'],
            'reference'           => ['nullable', 'string', 'max:150'],
            'notes'               => ['nullable', 'string', 'max:500'],
            'lines'              => ['required', 'array', 'min:1'],
            'lines.*.item_id'    => ['required', 'integer', 'exists:bs_items,id'],
            'lines.*.qty'        => ['required', 'numeric', 'min:0.01'],
        ]);

        try {
            $trx = DB::transaction(function () use ($v, $stock, $request) {
                $trx = BsStockTransaction::create([
                    'trx_number'          => $stock->nextNumber('ISS', BsStockTransaction::class, 'trx_date'),
                    'trx_type'            => 'issue',
                    'trx_date'            => $v['trx_date'],
                    'warehouse_id'        => $v['warehouse_id'],
                    'material_request_id' => $v['material_request_id'] ?? null,
                    'reference'           => $v['reference'] ?? null,
                    'notes'               => $v['notes'] ?? null,
                    'created_by'          => $request->user()->id,
                ]);
                foreach ($v['lines'] as $l) {
                    $trx->lines()->create(['item_id' => $l['item_id'], 'qty' => $l['qty'], 'unit_price' => 0]);
                }
                $stock->post($trx); // memvalidasi ketersediaan stok
                app(\App\Domain\BusinessSupport\Actions\PostInventoryJournal::class)->execute($trx); // jurnal GL otomatis
                return $trx;
            });
        } catch (BusinessRuleException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('bs.issue.index')->with('success', "Pengeluaran {$trx->trx_number} tercatat — stok berkurang.");
    }
}
