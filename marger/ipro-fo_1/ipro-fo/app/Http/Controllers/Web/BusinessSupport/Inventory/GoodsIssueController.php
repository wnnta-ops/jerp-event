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
        abort_unless($request->user()?->can('bs.issue.view'), 403);

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
        abort_unless($request->user()?->can('bs.issue.view'), 403);

        return Inertia::render('BusinessSupport/Inventory/GoodsIssues/Form', [
            'warehouses' => BsWarehouse::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'items'      => BsItem::where('is_active', true)->with('uom:id,code,name')->orderBy('name')->get(['id', 'code', 'name', 'uom_id'])
                ->map(fn ($i) => ['id' => $i->id, 'code' => $i->code, 'name' => $i->name, 'uom' => $i->uom?->code ?: $i->uom?->name])->values(),
            'materialRequests' => BsMaterialRequest::where('status', 'approved')->latest('request_date')->limit(50)->get(['id', 'mr_number']),
            // Saldo stok per (gudang, item) — dipakai layar untuk menampilkan sisa stok &
            // mencegah pengeluaran melebihi saldo sebelum dikirim ke server.
            'balances'   => $this->balances(),
        ]);
    }

    /**
     * Saldo stok berjalan per gudang & item (hanya yang saldonya masih ada).
     *
     * Dihitung dari kartu stok (bs_stock_movements) dengan rumus yang sama dengan
     * StockService::balance(), tapi sekali query untuk seluruh kombinasi — bukan per item —
     * supaya halaman tidak perlu bolak-balik ke server saat gudang/item diganti.
     *
     * @return array<int,array{warehouse_id:int, item_id:int, qty:float}>
     */
    private function balances(): array
    {
        return DB::table('bs_stock_movements')
            ->groupBy('warehouse_id', 'item_id')
            ->havingRaw('COALESCE(SUM(CASE WHEN direction = ? THEN qty ELSE -qty END), 0) > 0', ['in'])
            ->selectRaw('warehouse_id, item_id, COALESCE(SUM(CASE WHEN direction = ? THEN qty ELSE -qty END), 0) as qty', ['in'])
            ->get()
            ->map(fn ($r) => [
                'warehouse_id' => (int) $r->warehouse_id,
                'item_id'      => (int) $r->item_id,
                'qty'          => (float) $r->qty,
            ])->values()->all();
    }

    public function store(Request $request, StockService $stock): RedirectResponse
    {
        abort_unless($request->user()?->can('bs.issue.create'), 403);

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
