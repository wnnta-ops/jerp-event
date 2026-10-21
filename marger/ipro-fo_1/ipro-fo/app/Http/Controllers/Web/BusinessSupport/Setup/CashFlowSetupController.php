<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Setup;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsCashFlowCategory;
use App\Domain\BusinessSupport\Models\BsCashFlowMapping;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * GL Setup → Kategori Cash Flow (Direct/Indirect) + Mapping Cash Flow Direct.
 */
class CashFlowSetupController extends Controller
{
    // ── Kategori Cash Flow ────────────────────────────────────────────────
    public function categories(Request $request): Response
    {
        abort_unless($request->user()?->can('gl.cf-category.view'), 403);
        $kind = in_array($request->input('kind'), ['direct', 'indirect'], true) ? $request->input('kind') : 'direct';

        return Inertia::render('BusinessSupport/Setup/CashFlowCategories', [
            'kind'       => $kind,
            'categories' => BsCashFlowCategory::where('kind', $kind)->orderBy('sort_order')->orderBy('code')->get(),
            'activities' => BsCashFlowCategory::ACTIVITIES,
        ]);
    }

    public function storeCategory(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->canAny(['gl.cf-category.create', 'businesssupport.setup.manage']), 403);
        BsCashFlowCategory::create($this->categoryData($request));

        return back()->with('success', 'Kategori arus kas ditambahkan.');
    }

    public function updateCategory(Request $request, BsCashFlowCategory $category): RedirectResponse
    {
        abort_unless($request->user()?->canAny(['gl.cf-category.update', 'businesssupport.setup.manage']), 403);
        $category->update($this->categoryData($request, $category->id, $category->kind));

        return back()->with('success', 'Kategori arus kas diperbarui.');
    }

    public function destroyCategory(Request $request, BsCashFlowCategory $category): RedirectResponse
    {
        abort_unless($request->user()?->canAny(['gl.cf-category.delete', 'businesssupport.setup.manage']), 403);
        $category->delete();

        return back()->with('success', 'Kategori arus kas dihapus.');
    }

    // ── Mapping Cash Flow Direct (akun → kategori) ────────────────────────
    public function mapping(Request $request): Response
    {
        abort_unless($request->user()?->can('gl.cf-mapping.view'), 403);

        return Inertia::render('BusinessSupport/Setup/CashFlowMapping', [
            'mappings'   => BsCashFlowMapping::with(['account:id,code,name', 'category:id,code,name,activity'])->get(),
            'accounts'   => BsAccount::where('is_postable', true)->orderBy('code')->get(['id', 'code', 'name']),
            'categories' => BsCashFlowCategory::where('kind', 'direct')->where('is_active', true)->orderBy('sort_order')->orderBy('code')->get(['id', 'code', 'name', 'activity']),
        ]);
    }

    public function storeMapping(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->canAny(['gl.cf-mapping.create', 'businesssupport.setup.manage']), 403);
        $data = $this->mappingData($request);
        // Satu akun → satu kategori; upsert agar tidak bentrok unique.
        BsCashFlowMapping::updateOrCreate(['account_id' => $data['account_id']], ['category_id' => $data['category_id']]);

        return back()->with('success', 'Mapping arus kas disimpan.');
    }

    public function updateMapping(Request $request, BsCashFlowMapping $mapping): RedirectResponse
    {
        abort_unless($request->user()?->canAny(['gl.cf-mapping.update', 'businesssupport.setup.manage']), 403);
        $mapping->update(['category_id' => $this->mappingData($request)['category_id']]);

        return back()->with('success', 'Mapping arus kas diperbarui.');
    }

    public function destroyMapping(Request $request, BsCashFlowMapping $mapping): RedirectResponse
    {
        abort_unless($request->user()?->canAny(['gl.cf-mapping.delete', 'businesssupport.setup.manage']), 403);
        $mapping->delete();

        return back()->with('success', 'Mapping arus kas dihapus.');
    }

    /** @return array<string,mixed> */
    private function categoryData(Request $request, ?int $id = null, ?string $kind = null): array
    {
        $data = $request->validate([
            'kind'       => ['required', Rule::in(['direct', 'indirect'])],
            'code'       => ['required', 'string', 'max:16'],
            'name'       => ['required', 'string', 'max:120'],
            'activity'   => ['nullable', Rule::in(['operating', 'investing', 'financing'])],
            'sort_order' => ['nullable', 'integer'],
            'is_active'  => ['boolean'],
        ]);
        $effKind = $kind ?? $data['kind'];
        // unik per (tenant, kind, code)
        $request->validate([
            'code' => [Rule::unique('bs_cash_flow_categories', 'code')->where('kind', $effKind)->ignore($id)->whereNull('deleted_at')],
        ]);
        $data['sort_order'] = $data['sort_order'] ?? 0;

        return $data;
    }

    /** @return array{account_id:int,category_id:int} */
    private function mappingData(Request $request): array
    {
        return $request->validate([
            'account_id'  => ['required', 'integer', 'exists:bs_accounts,id'],
            'category_id' => ['required', 'integer', 'exists:bs_cash_flow_categories,id'],
        ]);
    }
}
