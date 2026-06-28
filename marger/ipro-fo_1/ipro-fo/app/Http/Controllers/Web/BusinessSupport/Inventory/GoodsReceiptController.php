<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Inventory;

use App\Domain\BusinessSupport\Models\BsItem;
use App\Domain\BusinessSupport\Models\BsPurchaseOrder;
use App\Domain\BusinessSupport\Models\BsStockTransaction;
use App\Domain\BusinessSupport\Models\BsSupplier;
use App\Domain\BusinessSupport\Models\BsWarehouse;
use App\Domain\BusinessSupport\Services\StockService;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class GoodsReceiptController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->canModule('bs'), 403);

        $rows = BsStockTransaction::query()
            ->where('trx_type', 'receipt')
            ->with(['warehouse:id,code,name', 'supplier:id,code,name'])
            ->withCount('lines')
            ->latest('trx_date')->latest('id')
            ->paginate(50);

        return Inertia::render('BusinessSupport/Inventory/GoodsReceipts/Index', ['receipts' => $rows]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()?->canModule('bs'), 403);

        $pos = BsPurchaseOrder::query()
            ->where('status', 'approved')
            ->with(['supplier:id,code,name', 'lines.item:id,code,name'])
            ->latest('po_date')->limit(50)->get()
            ->map(fn ($po) => [
                'id' => $po->id, 'po_number' => $po->po_number, 'supplier_id' => $po->supplier_id,
                'supplier' => $po->supplier?->name,
                'lines' => $po->lines->map(fn ($l) => [
                    'item_id' => $l->item_id, 'item' => $l->item?->code.' - '.$l->item?->name,
                    'qty' => (float) $l->qty, 'qty_received' => (float) $l->qty_received,
                    'outstanding' => (float) $l->qty - (float) $l->qty_received, 'unit_price' => (float) $l->unit_price,
                ]),
            ]);

        return Inertia::render('BusinessSupport/Inventory/GoodsReceipts/Form', [
            'warehouses' => BsWarehouse::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'suppliers'  => BsSupplier::where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']),
            'items'      => BsItem::where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']),
            'purchaseOrders' => $pos,
        ]);
    }

    public function store(Request $request, StockService $stock): RedirectResponse
    {
        abort_unless($request->user()?->canModule('bs'), 403);

        $v = $request->validate([
            'warehouse_id'      => ['required', 'integer', 'exists:bs_warehouses,id'],
            'supplier_id'       => ['nullable', 'integer', 'exists:bs_suppliers,id'],
            'purchase_order_id' => ['nullable', 'integer', 'exists:bs_purchase_orders,id'],
            'trx_date'          => ['required', 'date'],
            'reference'         => ['nullable', 'string', 'max:150'],
            'notes'             => ['nullable', 'string', 'max:500'],
            'lines'              => ['required', 'array', 'min:1'],
            'lines.*.item_id'    => ['required', 'integer', 'exists:bs_items,id'],
            'lines.*.qty'        => ['required', 'numeric', 'min:0.01'],
            'lines.*.unit_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        $trx = DB::transaction(function () use ($v, $stock, $request) {
            $trx = BsStockTransaction::create([
                'trx_number'        => $stock->nextNumber('RCV', BsStockTransaction::class, 'trx_date'),
                'trx_type'          => 'receipt',
                'trx_date'          => $v['trx_date'],
                'warehouse_id'      => $v['warehouse_id'],
                'supplier_id'       => $v['supplier_id'] ?? null,
                'purchase_order_id' => $v['purchase_order_id'] ?? null,
                'reference'         => $v['reference'] ?? null,
                'notes'             => $v['notes'] ?? null,
                'created_by'        => $request->user()->id,
            ]);
            foreach ($v['lines'] as $l) {
                $trx->lines()->create(['item_id' => $l['item_id'], 'qty' => $l['qty'], 'unit_price' => $l['unit_price'] ?? 0]);
            }
            $stock->post($trx);
            app(\App\Domain\BusinessSupport\Actions\PostInventoryJournal::class)->execute($trx); // jurnal GL otomatis
            return $trx;
        });

        $journal = \App\Domain\BusinessSupport\Models\BsJournal::where('source', 'inventory')->where('reference', $trx->trx_number)->latest('id')->first();
        $note = $journal ? " · jurnal {$journal->journal_number} terbentuk (Dr Persediaan / Cr Hutang)" : '';

        return redirect()->route('bs.receipt.index')->with('success', "Penerimaan {$trx->trx_number} tercatat — stok bertambah{$note}.");
    }
}
