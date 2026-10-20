<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Inventory;

use App\Domain\BusinessSupport\Models\BsItem;
use App\Domain\BusinessSupport\Models\BsStockCategory;
use App\Domain\BusinessSupport\Models\BsUom;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ItemController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('bs.item.view'), 403);

        $rows = BsItem::query()
            ->with(['category:id,code,name', 'uom:id,code,name'])
            ->when($request->input('search'), fn ($q, $s) => $q->where(fn ($w) => $w->where('code', 'like', "%{$s}%")->orWhere('name', 'like', "%{$s}%")->orWhere('brand', 'like', "%{$s}%")))
            ->when($request->input('category_id'), fn ($q, $v) => $q->where('stock_category_id', $v))
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        // Stok on-hand per item (ringkas).
        $balances = \App\Domain\BusinessSupport\Models\BsStockMovement::query()
            ->selectRaw("item_id, COALESCE(SUM(CASE WHEN direction='in' THEN qty ELSE -qty END),0) as bal")
            ->groupBy('item_id')->pluck('bal', 'item_id');

        $rows->getCollection()->transform(function ($i) use ($balances) {
            $i->on_hand = (float) ($balances[$i->id] ?? 0);
            return $i;
        });

        return Inertia::render('BusinessSupport/Inventory/Items', [
            'items'      => $rows,
            'categories' => BsStockCategory::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'uoms'       => BsUom::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'types'      => BsItem::TYPES,
            'filters'    => $request->only(['search', 'category_id']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);
        BsItem::create($this->data($request));

        return back()->with('success', 'Item ditambahkan.');
    }

    public function update(Request $request, BsItem $item): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);
        $item->update($this->data($request, $item->id));

        return back()->with('success', 'Item diperbarui.');
    }

    public function destroy(Request $request, BsItem $item): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);
        $item->delete();

        return back()->with('success', 'Item dihapus.');
    }

    /** @return array<string,mixed> */
    private function data(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'stock_category_id' => ['nullable', 'integer', 'exists:bs_stock_categories,id'],
            'uom_id'            => ['nullable', 'integer', 'exists:bs_uoms,id'],
            'code'              => ['required', 'string', 'max:48', Rule::unique('bs_items', 'code')->ignore($id)->whereNull('deleted_at')],
            'name'              => ['required', 'string', 'max:150'],
            'brand'             => ['nullable', 'string', 'max:100'],
            'type'              => ['required', Rule::in(array_keys(BsItem::TYPES))],
            'min_stock'         => ['nullable', 'numeric', 'min:0'],
            'is_active'         => ['boolean'],
        ]);
    }
}
