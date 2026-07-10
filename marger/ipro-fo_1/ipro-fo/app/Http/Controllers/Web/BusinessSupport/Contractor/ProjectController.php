<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Contractor;

use App\Domain\BusinessSupport\Models\BsProject;
use App\Domain\Foundation\Tenant\Models\Project;
use App\Domain\Master\Unit\Models\Unit;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\BusinessSupport\Contractor\Concerns\MapsUnitBast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Master Project (Contractor Management → Setup → Project). */
class ProjectController extends Controller
{
    use MapsUnitBast;

    public function index(Request $request): Response
    {
        abort_unless($request->user()?->canModule('contractor'), 403);

        return Inertia::render('BusinessSupport/Contractor/Project', [
            'projects' => BsProject::orderBy('code')->get(['id', 'code', 'name', 'dept', 'is_active', 'notes']),
        ]);
    }

    /**
     * Detail Project → daftar unit di dalamnya + status Sudah/Belum BAST.
     * Jembatan BsProject → Sales Project dicocokkan lewat kode (fallback: nama).
     */
    public function show(Request $request, BsProject $project): Response
    {
        abort_unless($request->user()?->canModule('contractor'), 403);

        $sales = Project::where('code', $project->code)->first()
            ?? Project::whereRaw('LOWER(name) = ?', [mb_strtolower($project->name)])->first();

        $rows = collect();
        if ($sales) {
            $units = Unit::where('project_id', $sales->id)
                ->with([
                    'cluster:id,code,name', 'block:id,code',
                    'unitType:id,code,name,lt,lb', 'project:id,code,name,location',
                ])
                ->orderBy('unit_code')
                ->get();
            $bastIds = $this->bastUnitIds($units->pluck('id')->all());
            $rows    = $units->map(fn (Unit $u) => $this->mapUnitRow($u, in_array($u->id, $bastIds, true)))->values();
        }

        return Inertia::render('BusinessSupport/Contractor/ProjectDetail', [
            'project'      => $project->only(['id', 'code', 'name', 'dept', 'notes']),
            'salesProject' => $sales?->only(['id', 'code', 'name', 'location']),
            'rows'         => $rows,
            'summary'      => [
                'total' => $rows->count(),
                'sudah' => $rows->where('bast_status', 'sudah')->count(),
                'belum' => $rows->where('bast_status', 'belum')->count(),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);
        $v = $this->validateData($request);
        BsProject::create(array_merge($v, ['tenant_id' => tenant_id(), 'created_by' => $request->user()->id]));

        return back()->with('success', 'Project ditambahkan.');
    }

    public function update(Request $request, BsProject $project): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);
        $project->update($this->validateData($request));

        return back()->with('success', 'Project diperbarui.');
    }

    public function destroy(Request $request, BsProject $project): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);
        $project->delete();

        return back()->with('success', 'Project dihapus.');
    }

    /** @return array<string,mixed> */
    private function validateData(Request $request): array
    {
        return $request->validate([
            'code'      => ['required', 'string', 'max:30'],
            'name'      => ['required', 'string', 'max:150'],
            'dept'      => ['nullable', 'string', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
            'notes'     => ['nullable', 'string', 'max:200'],
        ]);
    }
}
