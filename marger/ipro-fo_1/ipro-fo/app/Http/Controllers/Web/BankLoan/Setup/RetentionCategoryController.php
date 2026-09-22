<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BankLoan\Setup;

use App\Domain\BankLoan\Models\RetentionCategory;
use App\Domain\Foundation\Tenant\Models\Project;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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
            // Daftar pilihan datang dari model, bukan disalin di Vue: dua salinan berarti
            // menambah satu pilihan harus diingat di dua tempat.
            'syncOptions' => RetentionCategory::KPR_SYNC,
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
            // Wajib: kategori yang belum disinkronkan akan berdiri sebagai kolomnya SENDIRI di
            // Master Data KPR — terlihat seperti kategori yang memang terpisah, padahal hanya
            // belum diisi. Ditegakkan di sini sekaligus untuk store() dan update().
            'kpr_sync'   => ['required', Rule::in(array_keys(RetentionCategory::KPR_SYNC))],
            'keterangan' => ['nullable', 'string', 'max:1000'],          // #69 "DAJAM apa"
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active'  => ['boolean'],
        ], [
            'kpr_sync.required' => 'Kategori retensi harus disinkronkan dengan Master Data KPR.',
            'kpr_sync.in'       => 'Pilihan sinkron Master Data KPR tidak dikenal.',
        ]);

        // Jamin 0 (bukan NULL) bila persentase kosong, agar konsisten dgn default kolom.
        $data['percentage'] = $data['percentage'] ?? 0;

        return $data;
    }
}
