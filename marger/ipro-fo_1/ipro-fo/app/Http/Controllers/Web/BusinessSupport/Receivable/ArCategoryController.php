<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Receivable;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsArCategory;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ArCategoryController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->canModule('ar'), 403);

        $rows = BsArCategory::with(['incomeAccount:id,code,name', 'receivableAccount:id,code,name'])
            ->when($request->input('search'), fn ($q, $s) => $q->where(fn ($w) => $w->where('code', 'like', "%{$s}%")->orWhere('name', 'like', "%{$s}%")))
            ->orderBy('name')->get()
            ->map(fn ($c) => [
                'id' => $c->id, 'code' => $c->code, 'name' => $c->name, 'is_active' => $c->is_active,
                'income_account_id' => $c->income_account_id, 'receivable_account_id' => $c->receivable_account_id,
                'income' => $c->incomeAccount?->code.' - '.$c->incomeAccount?->name,
                'receivable' => $c->receivableAccount?->code.' - '.$c->receivableAccount?->name,
            ]);

        return Inertia::render('BusinessSupport/Receivable/Categories', [
            'categories' => $rows,
            'filters'    => $request->only('search'),
            'accounts'   => BsAccount::where('is_active', true)->where('is_postable', true)->orderBy('code')->get(['id', 'code', 'name', 'type']),
            'defaultReceivable' => BsAccount::where('code', config('business_support.gl_accounts.receivable'))->value('id'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);
        BsArCategory::create($this->data($request));

        return back()->with('success', 'Kategori invoice ditambahkan.');
    }

    public function update(Request $request, BsArCategory $category): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);
        $category->update($this->data($request, $category->id));

        return back()->with('success', 'Kategori invoice diperbarui.');
    }

    public function destroy(Request $request, BsArCategory $category): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);
        $category->delete();

        return back()->with('success', 'Kategori invoice dihapus.');
    }

    /** @return array<string,mixed> */
    private function data(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'code'                  => ['required', 'string', 'max:40', Rule::unique('bs_ar_categories', 'code')->ignore($id)->where('tenant_id', tenant_id())],
            'name'                  => ['required', 'string', 'max:150'],
            'income_account_id'     => ['required', 'integer', 'exists:bs_accounts,id'],
            'receivable_account_id' => ['required', 'integer', 'exists:bs_accounts,id'],
            'is_active'             => ['boolean'],
        ]);
    }
}
