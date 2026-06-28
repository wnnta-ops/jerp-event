<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Inventory;

use App\Domain\BusinessSupport\Models\BsStockCategory;
use App\Domain\BusinessSupport\Models\BsUom;
use App\Domain\BusinessSupport\Models\BsWarehouse;
use App\Domain\Foundation\Tenant\Models\Project;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Setup master Inventory sederhana: Satuan (uom), Kategori Stok (category), Gudang (warehouse).
 */
class SetupMasterController extends Controller
{
    private const MAP = [
        'uom'       => [BsUom::class,           'Satuan Stok',    false],
        'category'  => [BsStockCategory::class, 'Kategori Stok',  false],
        'warehouse' => [BsWarehouse::class,     'Lokasi / Gudang', true],
    ];

    public function index(Request $request, string $kind): Response
    {
        abort_unless($request->user()?->canModule('bs'), 403);
        [$model, $title, $hasProject] = $this->resolve($kind);

        $rows = $model::query()
            ->when($hasProject, fn ($q) => $q->with('project:id,name'))
            ->when($request->input('search'), fn ($q, $s) => $q->where(fn ($w) => $w->where('code', 'like', "%{$s}%")->orWhere('name', 'like', "%{$s}%")))
            ->orderBy('code')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('BusinessSupport/Inventory/SetupMaster', [
            'kind'       => $kind,
            'title'      => $title,
            'hasProject' => $hasProject,
            'rows'       => $rows,
            'projects'   => $hasProject ? Project::orderBy('name')->get(['id', 'name']) : [],
            'filters'    => $request->only(['search']),
        ]);
    }

    public function store(Request $request, string $kind): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);
        [$model, , $hasProject] = $this->resolve($kind);
        $model::create($this->data($request, $hasProject));

        return back()->with('success', 'Data ditambahkan.');
    }

    public function update(Request $request, string $kind, int $id): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);
        [$model, , $hasProject] = $this->resolve($kind);
        $model::findOrFail($id)->update($this->data($request, $hasProject));

        return back()->with('success', 'Data diperbarui.');
    }

    public function destroy(Request $request, string $kind, int $id): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);
        [$model] = $this->resolve($kind);
        $model::findOrFail($id)->delete();

        return back()->with('success', 'Data dihapus.');
    }

    /** @return array{0:class-string, 1:string, 2:bool} */
    private function resolve(string $kind): array
    {
        abort_unless(isset(self::MAP[$kind]), 404);

        return self::MAP[$kind];
    }

    /** @return array<string,mixed> */
    private function data(Request $request, bool $hasProject): array
    {
        $rules = [
            'code'      => ['required', 'string', 'max:48'],
            'name'      => ['required', 'string', 'max:150'],
            'is_active' => ['boolean'],
        ];
        if ($hasProject) {
            $rules['project_id'] = ['nullable', 'integer', 'exists:projects,id'];
        }

        return $request->validate($rules);
    }
}
