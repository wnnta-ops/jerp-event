<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BankLoan\Setup;

use App\Domain\BankLoan\Models\BankRejectCategory;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Setting KPR → Kategori Reject Bank (master alasan penolakan KPR oleh bank).
 */
class BankRejectCategoryController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('bankloan.setup.view'), 403);

        $rows = BankRejectCategory::query()
            ->when($request->input('search'), fn ($q, $s) => $q->where('name', 'like', "%{$s}%"))
            ->orderBy('sort_order')->orderBy('name')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('BankLoan/Setup/RejectBank/Index', [
            'categories' => $rows,
            'filters'    => $request->only(['search']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('bankloan.setup.manage'), 403);

        $data = $this->validateData($request);
        BankRejectCategory::create($data);

        return back()->with('success', "Kategori \"{$data['name']}\" ditambahkan.");
    }

    public function update(Request $request, BankRejectCategory $category): RedirectResponse
    {
        abort_unless($request->user()?->can('bankloan.setup.manage'), 403);

        $category->update($this->validateData($request, $category));

        return back()->with('success', "Kategori \"{$category->name}\" diperbarui.");
    }

    public function destroy(Request $request, BankRejectCategory $category): RedirectResponse
    {
        abort_unless($request->user()?->can('bankloan.setup.manage'), 403);

        $name = $category->name;
        $category->delete();

        return back()->with('success', "Kategori \"{$name}\" dihapus.");
    }

    /** @return array{name:string, sort_order:int, is_active:bool} */
    private function validateData(Request $request, ?BankRejectCategory $category = null): array
    {
        $v = $request->validate([
            // Nama unik per-tenant (abaikan baris yang sedang diedit + baris soft-deleted).
            // Rule::unique melewati TenantScope, jadi filter tenant_id + deleted_at eksplisit.
            'name'       => ['required', 'string', 'max:150',
                Rule::unique('kpr_bank_reject_categories', 'name')
                    ->where('tenant_id', tenant_id())
                    ->whereNull('deleted_at')
                    ->ignore($category?->id)],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active'  => ['nullable', 'boolean'],
        ], [
            'name.unique' => 'Kategori dengan nama ini sudah ada.',
        ]);

        return [
            'name'       => trim($v['name']),
            'sort_order' => (int) ($v['sort_order'] ?? 0),
            'is_active'  => (bool) ($v['is_active'] ?? true),
        ];
    }
}
