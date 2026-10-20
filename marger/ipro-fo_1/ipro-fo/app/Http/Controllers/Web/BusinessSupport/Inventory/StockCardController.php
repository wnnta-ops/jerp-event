<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Inventory;

use App\Domain\BusinessSupport\Models\BsItem;
use App\Domain\BusinessSupport\Models\BsStockMovement;
use App\Domain\BusinessSupport\Models\BsWarehouse;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Kartu Stok — ledger pergerakan + saldo on-hand per item/gudang.
 */
class StockCardController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('bs.stockcard.view'), 403);

        $movements = BsStockMovement::query()
            ->with(['item:id,code,name', 'warehouse:id,code,name'])
            ->when($request->input('item_id'), fn ($q, $v) => $q->where('item_id', $v))
            ->when($request->input('warehouse_id'), fn ($q, $v) => $q->where('warehouse_id', $v))
            ->orderByDesc('moved_at')->orderByDesc('id')
            ->paginate(25)->withQueryString();

        // Ringkasan on-hand per item.
        $onHand = BsStockMovement::query()
            ->selectRaw("item_id, COALESCE(SUM(CASE WHEN direction='in' THEN qty ELSE -qty END),0) as bal")
            ->groupBy('item_id')
            ->havingRaw('bal <> 0')
            ->pluck('bal', 'item_id');

        $summary = BsItem::whereIn('id', $onHand->keys())->get(['id', 'code', 'name'])
            ->map(fn ($i) => ['code' => $i->code, 'name' => $i->name, 'on_hand' => (float) $onHand[$i->id]])
            ->values();

        return Inertia::render('BusinessSupport/Inventory/StockCard', [
            'movements'  => $movements,
            'summary'    => $summary,
            'items'      => BsItem::orderBy('name')->get(['id', 'code', 'name']),
            'warehouses' => BsWarehouse::orderBy('code')->get(['id', 'code', 'name']),
            'filters'    => $request->only(['item_id', 'warehouse_id']),
        ]);
    }
}
