<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Finance;

use App\Domain\Finance\Bank\Models\BankAccount;
use App\Domain\Foundation\Tenant\Models\Company;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Setup Rekening Bank (eProperty: Finance & AR → Setup Rekening).
 */
class BankAccountController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('finance.va.view'), 403);

        return Inertia::render('Finance/BankAccount/Index', [
            'rows' => BankAccount::query()->with('company:id,name')
                ->when($request->input('search'), fn ($q, $s) => $q->where(fn ($w) => $w->where('account_name', 'like', "%{$s}%")->orWhere('bank_name', 'like', "%{$s}%")->orWhere('account_number', 'like', "%{$s}%")))
                ->orderByDesc('is_active')->orderBy('bank_name')->paginate(25)->withQueryString(),
            'filters'   => $request->only('search'),
            'companies' => Company::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('finance.va.assign'), 403);
        BankAccount::create($this->validateData($request));

        return back()->with('success', 'Rekening ditambahkan.');
    }

    public function update(Request $request, BankAccount $rekening): RedirectResponse
    {
        abort_unless($request->user()?->can('finance.va.assign'), 403);
        $rekening->update($this->validateData($request));

        return back()->with('success', 'Rekening diperbarui.');
    }

    public function destroy(Request $request, BankAccount $rekening): RedirectResponse
    {
        abort_unless($request->user()?->can('finance.va.assign'), 403);
        $rekening->delete();

        return back()->with('success', 'Rekening dihapus.');
    }

    /** @return array<string,mixed> */
    private function validateData(Request $request): array
    {
        return $request->validate([
            'company_id'      => ['nullable', 'integer', 'exists:companies,id'],
            'account_name'    => ['required', 'string', 'max:150'],
            'account_number'  => ['required', 'string', 'max:64'],
            'bank_name'       => ['required', 'string', 'max:64'],
            'branch'          => ['nullable', 'string', 'max:150'],
            'is_virtual_pool' => ['boolean'],
            'is_active'       => ['boolean'],
        ]);
    }
}
