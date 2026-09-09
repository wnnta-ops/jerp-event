<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Setup;

use App\Domain\BusinessSupport\Models\BsCostCenter;
use App\Domain\Foundation\Tenant\Models\Project;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Business Support → Setup → Cost Center / Divisi.
 */
class CostCenterController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('gl.cost-center.view'), 403);

        $rows = BsCostCenter::query()
            ->with('project:id,name')
            ->when($request->input('search'), fn ($q, $s) => $q->where(fn ($w) => $w->where('code', 'like', "%{$s}%")->orWhere('name', 'like', "%{$s}%")))
            ->orderBy('code')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('BusinessSupport/Setup/CostCenters', [
            'costCenters' => $rows,
            'projects'    => Project::orderBy('name')->get(['id', 'name']),
            'filters'     => $request->only(['search']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);
        BsCostCenter::create($this->data($request));

        return back()->with('success', 'Cost center ditambahkan.');
    }

    public function update(Request $request, BsCostCenter $costCenter): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);
        $costCenter->update($this->data($request, $costCenter->id));

        return back()->with('success', 'Cost center diperbarui.');
    }

    public function destroy(Request $request, BsCostCenter $costCenter): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);
        $costCenter->delete();

        return back()->with('success', 'Cost center dihapus.');
    }

    /** @return array<string,mixed> */
    private function data(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'code'       => ['required', 'string', 'max:32', Rule::unique('bs_cost_centers', 'code')->ignore($id)->whereNull('deleted_at')],
            'name'       => ['required', 'string', 'max:150'],
            'manager'    => ['nullable', 'string', 'max:150'],
            'is_active'  => ['boolean'],
        ]);
    }
}
