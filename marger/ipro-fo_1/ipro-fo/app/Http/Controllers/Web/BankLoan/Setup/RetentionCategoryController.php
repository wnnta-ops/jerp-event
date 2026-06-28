<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BankLoan\Setup;

use App\Domain\BankLoan\Models\RetentionCategory;
use App\Domain\Foundation\Tenant\Models\Project;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Setting KPR → Kategori Retensi (master kategori retensi).
 */
class RetentionCategoryController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('bankloan.setup.view'), 403);

        $rows = RetentionCategory::query()
            ->with('project:id,name')
            ->when($request->input('search'), fn ($q, $s) => $q->where(fn ($w) => $w->where('code', 'like', "%{$s}%")->orWhere('name', 'like', "%{$s}%")))
            ->when($request->input('project_id'), fn ($q, $v) => $q->where('project_id', $v))
            ->orderBy('sort_order')->orderBy('code')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('BankLoan/Setup/Retensi/Index', [
            'categories' => $rows,
            'projects'   => Project::orderBy('name')->get(['id', 'name']),
            'filters'    => $request->only(['search', 'project_id']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('bankloan.setup.manage'), 403);

        $data = $this->validateData($request);
        RetentionCategory::create($data);

        return back()->with('success', "Kategori retensi {$data['name']} ditambahkan.");
    }

    public function update(Request $request, RetentionCategory $category): RedirectResponse
    {
        abort_unless($request->user()?->can('bankloan.setup.manage'), 403);

        $category->update($this->validateData($request));

        return back()->with('success', "Kategori retensi {$category->name} diperbarui.");
    }

    public function destroy(Request $request, RetentionCategory $category): RedirectResponse
    {
        abort_unless($request->user()?->can('bankloan.setup.manage'), 403);

        $name = $category->name;
        $category->delete();

        return back()->with('success', "Kategori retensi {$name} dihapus.");
    }

    /** @return array<string,mixed> */
    private function validateData(Request $request): array
    {
        $data = $request->validate([
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'code'       => ['required', 'string', 'max:64'],
            'name'       => ['required', 'string', 'max:150'],
            'percentage' => ['nullable', 'numeric', 'min:0', 'max:100'], // #69 persentase default
            'is_dajam'   => ['boolean'],                                  // #69 DAJAM = pengurangan
            'keterangan' => ['nullable', 'string', 'max:1000'],          // #69 "DAJAM apa"
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active'  => ['boolean'],
        ]);

        // Jamin 0 (bukan NULL) bila persentase kosong, agar konsisten dgn default kolom.
        $data['percentage'] = $data['percentage'] ?? 0;

        return $data;
    }
}
