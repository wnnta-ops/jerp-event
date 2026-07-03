<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Setup;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsCashFlowCategory;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Business Support → Setup → Chart of Account (General Ledger).
 */
class AccountController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->canModule('gl'), 403);

        $search = $request->input('search');
        $type   = $request->input('type');

        $rows = BsAccount::query()
            ->with(['cfDirectCategory:id,code,name', 'cfIndirectCategory:id,code,name'])
            ->when($search, fn ($q, $s) => $q->where(fn ($w) => $w->where('code', 'like', "%{$s}%")->orWhere('name', 'like', "%{$s}%")))
            ->when($type, fn ($q, $v) => $q->where('type', $v))
            // Kelompokkan per kategori (head), level-1 header lebih dulu, lalu urut kode.
            ->orderByRaw("COALESCE(category_code, substr(code,1,1)) asc, case when level = 1 then '0' else code end asc")
            ->get(['id', 'code', 'category_code', 'name', 'type', 'level', 'parent_code', 'normal_balance', 'is_postable', 'is_active', 'cf_direct_category_id', 'cf_indirect_category_id']);

        $cfCats = fn (string $kind) => BsCashFlowCategory::where('kind', $kind)->where('is_active', true)
            ->orderBy('sort_order')->orderBy('code')->get(['id', 'code', 'name', 'activity']);

        return Inertia::render('BusinessSupport/Setup/Accounts', [
            'accounts'    => $rows,
            'types'       => BsAccount::TYPES,
            'cfDirect'    => $cfCats('direct'),
            'cfIndirect'  => $cfCats('indirect'),
            'filters'     => ['search' => $search, 'type' => $type],
            'stats'       => [
                'total'    => BsAccount::count(),
                'postable' => BsAccount::where('is_postable', true)->count(),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);
        BsAccount::create($this->data($request));

        return back()->with('success', 'Akun ditambahkan.');
    }

    public function update(Request $request, BsAccount $account): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);
        $account->update($this->data($request, $account->id));

        return back()->with('success', 'Akun diperbarui.');
    }

    public function destroy(Request $request, BsAccount $account): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);
        $account->delete();

        return back()->with('success', 'Akun dihapus.');
    }

    /** @return array<string,mixed> */
    private function data(Request $request, ?int $id = null): array
    {
        $data = $request->validate([
            'code'           => ['required', 'string', 'max:32', Rule::unique('bs_accounts', 'code')->ignore($id)->whereNull('deleted_at')],
            'name'           => ['required', 'string', 'max:150'],
            'type'           => ['required', Rule::in(array_keys(BsAccount::TYPES))],
            'normal_balance' => ['required', Rule::in(['D', 'K'])],
            'is_postable'    => ['boolean'],
            'is_active'      => ['boolean'],
            'cf_direct_category_id'   => ['nullable', 'integer', Rule::exists('bs_cash_flow_categories', 'id')->where('kind', 'direct')],
            'cf_indirect_category_id' => ['nullable', 'integer', Rule::exists('bs_cash_flow_categories', 'id')->where('kind', 'indirect')],
        ]);

        // Turunkan level & parent dari kode hierarkis (1/3/5/7/9/11 digit).
        $len = strlen($data['code']);
        $data['level']         = $len % 2 === 1 && $len <= 11 ? intdiv($len + 1, 2) : 6;
        $data['parent_code']   = $len > 1 ? substr($data['code'], 0, max(1, $len - 2)) : null;
        $data['category_code'] = BsAccount::categoryOf($data['code']);

        return $data;
    }
}
