<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Master;

use App\Domain\Foundation\Tenant\Models\Cluster;
use App\Domain\Foundation\Tenant\Models\Project;
use App\Domain\Master\Unit\Enums\UnitStatus;
use App\Domain\Master\Unit\Models\Unit;
use App\Domain\Master\Unit\Models\UnitType;
use App\Domain\Master\Unit\Services\UnitService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Master\Unit\StoreUnitRequest;
use App\Http\Requests\Master\Unit\UpdateUnitRequest;
use App\Http\Resources\UnitResource;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class UnitController extends Controller
{
    public function __construct(private readonly UnitService $service) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Unit::class);

        $filters   = $request->only(['search', 'project_id', 'cluster_id', 'status', 'unit_type_id']);
        $paginator = $this->service->paginate($filters, (int) $request->integer('per_page', 50));

        return Inertia::render('Master/Unit/Index', [
            'units'    => UnitResource::collection($paginator),
            'filters'  => $filters,
            'projects' => Project::select('id', 'code', 'name')->get(),
            'clusters' => Cluster::select('id', 'project_id', 'code', 'name')->get(),
            'statuses' => collect(UnitStatus::cases())->map(fn ($s) => ['value' => $s->value, 'label' => $s->label()]),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Unit::class);

        return Inertia::render('Master/Unit/Create', [
            'projects'   => Project::select('id', 'code', 'name')->get(),
            'clusters'   => Cluster::select('id', 'project_id', 'code', 'name')->get(),
            'unit_types' => UnitType::select('id', 'project_id', 'code', 'name', 'lt', 'lb')->get(),
        ]);
    }

    public function store(StoreUnitRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $data['current_price'] = $data['current_price'] ?? $data['base_price'];
        $data['status'] = $data['status'] ?? 'available';

        $unit = Unit::create($data);

        return redirect()->route('units.show', $unit)
            ->with('success', "Unit {$unit->unit_code} berhasil dibuat.");
    }

    public function show(Unit $unit): Response
    {
        $this->authorize('view', $unit);

        return Inertia::render('Master/Unit/Show', [
            'unit' => UnitResource::make($unit->load(['cluster', 'block', 'unitType', 'siteplanPoint'])),
        ]);
    }

    public function edit(Unit $unit): Response
    {
        $this->authorize('update', $unit);

        return Inertia::render('Master/Unit/Edit', [
            'unit'       => UnitResource::make($unit),
            'clusters'   => Cluster::where('project_id', $unit->project_id)->select('id', 'code', 'name')->get(),
            'unit_types' => UnitType::where('project_id', $unit->project_id)->select('id', 'code', 'name', 'lt', 'lb')->get(),
            'statuses'   => collect(UnitStatus::cases())->map(fn ($s) => ['value' => $s->value, 'label' => $s->label()]),
        ]);
    }

    public function update(UpdateUnitRequest $request, Unit $unit): RedirectResponse
    {
        $this->authorize('update', $unit);

        $unit->update($request->validated());

        return redirect()->route('units.show', $unit)
            ->with('success', "Unit {$unit->unit_code} berhasil diperbarui.");
    }

    public function destroy(Unit $unit): RedirectResponse
    {
        $this->authorize('delete', $unit);

        if ($unit->status->value !== 'available') {
            return back()->with('error', "Hanya unit AVAIL yang bisa dihapus.");
        }
        $unit->delete();

        return redirect()->route('units.index')->with('success', "Unit {$unit->unit_code} dihapus.");
    }
}
