<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\SetupSales;

use App\Domain\Foundation\Tenant\Models\Project;
use App\Domain\Master\Unit\Models\Pricelist;
use App\Domain\Master\Unit\Models\SalesPriceHistory;
use App\Domain\Master\Unit\Models\Unit;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Price List (eProperty: Setup Sales → Price List). Daftar harga + aktivasi ke unit.
 */
class PricelistController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('setupsales.view'), 403);

        return Inertia::render('SetupSales/Pricelist/Index', [
            'rows' => Pricelist::query()->with('project:id,name')->withCount('items')
                ->when($request->input('search'), fn ($q, $s) => $q->where('name', 'like', "%{$s}%"))
                ->orderByDesc('is_active')->orderByDesc('effective_from')
                ->paginate(20)->withQueryString(),
            'filters' => $request->only(['search']),
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()?->can('setupsales.manage'), 403);

        return Inertia::render('SetupSales/Pricelist/Form', [
            'projects' => Project::orderBy('name')->get(['id', 'name']),
        ]);
    }

    /** Ambil unit (beserta harga sekarang) untuk project tertentu — dipakai form. */
    public function units(Request $request): Response
    {
        abort_unless($request->user()?->can('setupsales.manage'), 403);
        $projectId = $request->integer('project_id');

        return Inertia::render('SetupSales/Pricelist/Form', [
            'projects'    => Project::orderBy('name')->get(['id', 'name']),
            'selectedProject' => $projectId,
            'units'       => Unit::query()->when($projectId, fn ($q, $id) => $q->where('project_id', $id))
                ->with('unitType:id,name')->orderBy('unit_code')->limit(1000)
                ->get(['id', 'unit_code', 'unit_type_id', 'current_price', 'status'])
                ->map(fn ($u) => ['id' => $u->id, 'unit_code' => $u->unit_code, 'type' => $u->unitType?->name, 'current_price' => (float) $u->current_price, 'status' => $u->status]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('setupsales.manage'), 403);
        $v = $request->validate([
            'project_id'     => ['required', 'integer', 'exists:projects,id'],
            'name'           => ['required', 'string', 'max:150'],
            'effective_from' => ['required', 'date'],
            'effective_to'   => ['nullable', 'date', 'after_or_equal:effective_from'],
            'activate'       => ['boolean'],
            'items'                => ['required', 'array', 'min:1'],
            'items.*.unit_id'      => ['required', 'integer', 'exists:units,id'],
            'items.*.price'        => ['required', 'numeric', 'min:0'],
            'items.*.discount_max_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $pricelist = DB::transaction(function () use ($v, $request) {
            $pricelist = Pricelist::create([
                'project_id' => $v['project_id'], 'name' => $v['name'],
                'effective_from' => $v['effective_from'], 'effective_to' => $v['effective_to'] ?? null,
                'is_active' => false,
            ]);
            foreach ($v['items'] as $it) {
                $pricelist->items()->create([
                    'unit_id' => $it['unit_id'], 'price' => $it['price'],
                    'discount_max_pct' => $it['discount_max_pct'] ?? 0,
                ]);
            }
            if ($request->boolean('activate')) {
                $this->activatePricelist($pricelist, $request->user()->id);
            }

            return $pricelist;
        });

        return redirect()->route('setup-sales.pricelist.show', $pricelist)->with('success', "Price List \"{$pricelist->name}\" dibuat.");
    }

    public function show(Request $request, Pricelist $pricelist): Response
    {
        abort_unless($request->user()?->can('setupsales.view'), 403);
        $pricelist->load(['project:id,name', 'items.unit:id,unit_code,current_price,status']);

        return Inertia::render('SetupSales/Pricelist/Show', [
            'pricelist' => $pricelist->toArray(),
        ]);
    }

    /** Aktivasi — set aktif & terapkan harga ke unit (catat history). */
    public function activate(Request $request, Pricelist $pricelist): RedirectResponse
    {
        abort_unless($request->user()?->can('setupsales.manage'), 403);
        $changed = DB::transaction(fn () => $this->activatePricelist($pricelist, $request->user()->id));

        return back()->with('success', "Price List diaktifkan. {$changed} harga unit diperbarui.");
    }

    public function destroy(Request $request, Pricelist $pricelist): RedirectResponse
    {
        abort_unless($request->user()?->can('setupsales.manage'), 403);
        $pricelist->delete();

        return redirect()->route('setup-sales.pricelist.index')->with('success', 'Price List dihapus.');
    }

    /** Terapkan harga price list ke unit + catat perubahan. Return jumlah unit berubah. */
    private function activatePricelist(Pricelist $pricelist, int $userId): int
    {
        // Non-aktifkan price list lain pada project sama.
        Pricelist::where('project_id', $pricelist->project_id)->where('id', '!=', $pricelist->id)->update(['is_active' => false]);
        $pricelist->update(['is_active' => true]);

        $changed = 0;
        foreach ($pricelist->items()->with('unit')->get() as $item) {
            $unit = $item->unit;
            if (! $unit) {
                continue;
            }
            $old = (float) $unit->current_price;
            $new = (float) $item->price;
            if (abs($old - $new) < 0.01) {
                continue;
            }
            SalesPriceHistory::create([
                'unit_id' => $unit->id, 'pricelist_id' => $pricelist->id,
                'old_price' => $old, 'new_price' => $new, 'source' => 'pricelist',
                'note' => "Aktivasi Price List #{$pricelist->id}", 'changed_by' => $userId,
            ]);
            $unit->update(['current_price' => $new]);
            $changed++;
        }

        return $changed;
    }
}
