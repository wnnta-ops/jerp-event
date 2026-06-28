<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Setup;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsJournal;
use App\Domain\BusinessSupport\Models\BsTransactionRule;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * GL Setup → Transaction Rule: definisi pemetaan transaksi sumber → template jurnal.
 */
class TransactionRuleController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->canModule('gl'), 403);

        $rules = BsTransactionRule::query()
            ->withCount('lines')
            ->when($request->input('source'), fn ($q, $s) => $q->where('source', $s))
            ->orderBy('source')->orderBy('sort_order')->orderBy('code')
            ->get();

        return Inertia::render('BusinessSupport/Setup/TransactionRules', [
            'rules'   => $rules,
            'sources' => BsJournal::SOURCES,
            'filters' => $request->only(['source']),
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);

        return Inertia::render('BusinessSupport/Setup/TransactionRuleForm', $this->formProps(null));
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);
        $data = $this->validateData($request);

        DB::transaction(function () use ($data): void {
            $rule = BsTransactionRule::create($this->header($data));
            $this->syncLines($rule, $data['lines']);
        });

        return redirect()->route('bs.gl.transaction-rule.index')->with('success', 'Transaction Rule dibuat.');
    }

    public function edit(Request $request, BsTransactionRule $rule): Response
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);
        $rule->load('lines.account:id,code,name');

        return Inertia::render('BusinessSupport/Setup/TransactionRuleForm', $this->formProps($rule));
    }

    public function update(Request $request, BsTransactionRule $rule): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);
        $data = $this->validateData($request, $rule->id);

        DB::transaction(function () use ($rule, $data): void {
            $rule->update($this->header($data));
            $rule->lines()->delete();
            $this->syncLines($rule, $data['lines']);
        });

        return redirect()->route('bs.gl.transaction-rule.index')->with('success', 'Transaction Rule diperbarui.');
    }

    public function destroy(Request $request, BsTransactionRule $rule): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);
        $rule->lines()->delete();
        $rule->delete();

        return back()->with('success', 'Transaction Rule dihapus.');
    }

    /** @return array<string,mixed> */
    private function formProps(?BsTransactionRule $rule): array
    {
        return [
            'rule'        => $rule ? [
                'id' => $rule->id, 'code' => $rule->code, 'name' => $rule->name, 'source' => $rule->source,
                'event' => $rule->event, 'description' => $rule->description, 'sort_order' => $rule->sort_order, 'is_active' => $rule->is_active,
                'lines' => $rule->lines->map(fn ($l) => [
                    'side' => $l->side, 'account_role' => $l->account_role, 'account_id' => $l->account_id,
                    'amount_basis' => $l->amount_basis, 'description' => $l->description,
                ])->values(),
            ] : null,
            'sources'     => BsJournal::SOURCES,
            'amountBases' => BsTransactionRule::AMOUNT_BASES,
            'accounts'    => BsAccount::where('is_postable', true)->orderBy('code')->get(['id', 'code', 'name']),
        ];
    }

    /** @return array<string,mixed> */
    private function header(array $data): array
    {
        return [
            'code' => $data['code'], 'name' => $data['name'], 'source' => $data['source'],
            'event' => $data['event'] ?? null, 'description' => $data['description'] ?? null,
            'sort_order' => $data['sort_order'] ?? 0, 'is_active' => $data['is_active'] ?? true,
        ];
    }

    private function syncLines(BsTransactionRule $rule, array $lines): void
    {
        foreach (array_values($lines) as $i => $l) {
            $rule->lines()->create([
                'sort_order'   => $i,
                'side'         => $l['side'],
                'account_role' => $l['account_role'],
                'account_id'   => $l['account_id'] ?? null,
                'amount_basis' => $l['amount_basis'] ?? 'total',
                'description'  => $l['description'] ?? null,
            ]);
        }
    }

    /** @return array<string,mixed> */
    private function validateData(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'code'                 => ['required', 'string', 'max:32', Rule::unique('bs_transaction_rules', 'code')->ignore($id)->whereNull('deleted_at')],
            'name'                 => ['required', 'string', 'max:150'],
            'source'               => ['required', 'string', 'max:16'],
            'event'                => ['nullable', 'string', 'max:40'],
            'description'          => ['nullable', 'string', 'max:255'],
            'sort_order'           => ['nullable', 'integer'],
            'is_active'            => ['boolean'],
            'lines'                => ['required', 'array', 'min:1'],
            'lines.*.side'         => ['required', Rule::in(['debit', 'credit'])],
            'lines.*.account_role' => ['required', 'string', 'max:120'],
            'lines.*.account_id'   => ['nullable', 'integer', 'exists:bs_accounts,id'],
            'lines.*.amount_basis' => ['required', 'string', 'max:24'],
            'lines.*.description'  => ['nullable', 'string', 'max:255'],
        ]);
    }
}
