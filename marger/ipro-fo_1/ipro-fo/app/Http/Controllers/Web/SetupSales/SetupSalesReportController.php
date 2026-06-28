<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\SetupSales;

use App\Domain\Master\Sales\Models\SalesPerson;
use App\Domain\Master\Unit\Models\Pricelist;
use App\Domain\Master\Unit\Models\SalesPriceHistory;
use App\Domain\Master\Unit\Models\Unit;
use App\Domain\Master\Unit\Models\UnitType;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Laporan Setup Sales: Master Sales, Price List, History Price List,
 * Summary Stock Per Tipe, Master Unit.
 */
class SetupSalesReportController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('setupsales.view'), 403);

        return Inertia::render('SetupSales/Reports/Index');
    }

    public function masterSales(Request $request): Response
    {
        abort_unless($request->user()?->can('setupsales.view'), 403);

        return Inertia::render('SetupSales/Reports/MasterSales', [
            'rows' => SalesPerson::query()->with('manager:id,name')
                ->orderBy('name')->get(['id', 'code', 'name', 'phone', 'email', 'manager_id', 'joined_at', 'is_active']),
        ]);
    }

    public function priceList(Request $request): Response
    {
        abort_unless($request->user()?->can('setupsales.view'), 403);
        $active = Pricelist::with(['project:id,name', 'items.unit:id,unit_code,status,unit_type_id', 'items.unit.unitType:id,name'])
            ->where('is_active', true)->orderBy('project_id')->get();

        $rows = $active->flatMap(fn ($pl) => $pl->items->map(fn ($it) => [
            'pricelist' => $pl->name, 'project' => $pl->project?->name,
            'unit_code' => $it->unit?->unit_code, 'type' => $it->unit?->unitType?->name,
            'status'    => $it->unit?->status, 'price' => (float) $it->price, 'discount_max_pct' => (float) $it->discount_max_pct,
        ]))->values();

        return Inertia::render('SetupSales/Reports/PriceList', [
            'rows'  => $rows,
            'total' => round((float) $rows->sum('price'), 2),
        ]);
    }

    public function historyPriceList(Request $request): Response
    {
        abort_unless($request->user()?->can('setupsales.view'), 403);

        $rows = SalesPriceHistory::query()->with('unit:id,unit_code')
            ->when($request->input('search'), fn ($q, $s) => $q->whereHas('unit', fn ($w) => $w->where('unit_code', 'like', "%{$s}%")))
            ->orderByDesc('id')->paginate(60)->withQueryString();

        $userMap = User::whereIn('id', collect($rows->items())->pluck('changed_by')->filter()->unique())->pluck('name', 'id');
        $rows->getCollection()->transform(fn ($r) => [
            'id' => $r->id, 'unit_code' => $r->unit?->unit_code,
            'old_price' => (float) $r->old_price, 'new_price' => (float) $r->new_price,
            'delta' => round((float) $r->new_price - (float) $r->old_price, 2),
            'source' => SalesPriceHistory::SOURCES[$r->source] ?? $r->source,
            'note' => $r->note, 'by' => $userMap[$r->changed_by] ?? null,
            'at' => $r->created_at?->toDateTimeString(),
        ]);

        return Inertia::render('SetupSales/Reports/HistoryPriceList', [
            'logs'    => $rows,
            'filters' => $request->only(['search']),
        ]);
    }

    public function summaryStock(Request $request): Response
    {
        abort_unless($request->user()?->can('setupsales.view'), 403);

        $types = UnitType::orderBy('name')->get(['id', 'code', 'name']);
        $agg = Unit::query()->select('unit_type_id', 'status', DB::raw('count(*) as c'), DB::raw('sum(current_price) as v'))
            ->groupBy('unit_type_id', 'status')->get();

        $rows = $types->map(function ($t) use ($agg) {
            $r = $agg->where('unit_type_id', $t->id);

            return [
                'code' => $t->code, 'name' => $t->name,
                'available' => (int) $r->where('status', 'available')->sum('c'),
                'booked'    => (int) $r->where('status', 'booked')->sum('c'),
                'sold'      => (int) $r->where('status', 'sold')->sum('c'),
                'total'     => (int) $r->sum('c'),
                'value'     => round((float) $r->sum('v'), 2),
            ];
        })->filter(fn ($r) => $r['total'] > 0)->values();

        return Inertia::render('SetupSales/Reports/SummaryStock', [
            'rows'   => $rows,
            'totals' => [
                'available' => (int) $rows->sum('available'),
                'booked'    => (int) $rows->sum('booked'),
                'sold'      => (int) $rows->sum('sold'),
                'total'     => (int) $rows->sum('total'),
                'value'     => round((float) $rows->sum('value'), 2),
            ],
        ]);
    }

    public function masterUnit(Request $request): Response
    {
        abort_unless($request->user()?->can('setupsales.view'), 403);

        $rows = Unit::query()->with('unitType:id,name', 'project:id,name', 'cluster:id,name')
            ->when($request->input('search'), fn ($q, $s) => $q->where('unit_code', 'like', "%{$s}%"))
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->orderBy('unit_code')->paginate(50)->withQueryString();

        $rows->getCollection()->transform(fn ($u) => [
            'id' => $u->id, 'unit_code' => $u->unit_code, 'project' => $u->project?->name,
            'type' => $u->unitType?->name, 'cluster' => $u->cluster?->name,
            'base_price' => (float) $u->base_price, 'current_price' => (float) $u->current_price, 'status' => $u->status,
        ]);

        return Inertia::render('SetupSales/Reports/MasterUnit', [
            'rows'    => $rows,
            'filters' => $request->only(['search', 'status']),
        ]);
    }
}
