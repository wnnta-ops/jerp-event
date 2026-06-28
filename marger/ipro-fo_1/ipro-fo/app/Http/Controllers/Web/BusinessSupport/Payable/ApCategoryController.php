<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Payable;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsApCategory;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ApCategoryController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->canModule('ap'), 403);

        $rows = BsApCategory::with(['debitAccount:id,code,name', 'payableAccount:id,code,name'])
            ->when($request->input('search'), fn ($q, $s) => $q->where(fn ($w) => $w->where('code', 'like', "%{$s}%")->orWhere('name', 'like', "%{$s}%")))
            ->orderBy('name')->get()
            ->map(fn ($c) => [
                'id' => $c->id, 'code' => $c->code, 'name' => $c->name, 'is_active' => $c->is_active,
                'debit_account_id' => $c->debit_account_id, 'payable_account_id' => $c->payable_account_id,
                'debit' => $c->debitAccount?->code.' - '.$c->debitAccount?->name,
                'payable' => $c->payableAccount?->code.' - '.$c->payableAccount?->name,
            ]);

        return Inertia::render('BusinessSupport/Payable/Categories', [
            'categories' => $rows,
            'filters'    => $request->only('search'),
            // Hanya akun transaksi (is_postable) — CoA induk/header tidak ditampilkan. Tetap bisa dicari via SearchSelect.
            'accounts'   => BsAccount::where('is_active', true)->where('is_postable', true)->orderBy('code')->get(['id', 'code', 'name', 'type']),
            'defaultPayable' => BsAccount::where('code', config('business_support.gl_accounts.payable'))->value('id'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);
        BsApCategory::create($this->data($request));

        return back()->with('success', 'Kategori invoice ditambahkan.');
    }

    public function update(Request $request, BsApCategory $category): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);
        $category->update($this->data($request, $category->id));

        return back()->with('success', 'Kategori invoice diperbarui.');
    }

    public function destroy(Request $request, BsApCategory $category): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);
        $category->delete();

        return back()->with('success', 'Kategori invoice dihapus.');
    }

    /** @return array<string,mixed> */
    private function data(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'code'               => ['required', 'string', 'max:40', Rule::unique('bs_ap_categories', 'code')->ignore($id)->where('tenant_id', tenant_id())],
            'name'               => ['required', 'string', 'max:150'],
            'debit_account_id'   => ['required', 'integer', 'exists:bs_accounts,id'],
            'payable_account_id' => ['required', 'integer', 'exists:bs_accounts,id'],
            'is_active'          => ['boolean'],
        ]);
    }
}
