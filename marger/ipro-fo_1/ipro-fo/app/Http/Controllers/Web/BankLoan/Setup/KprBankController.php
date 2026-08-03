<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BankLoan\Setup;

use App\Domain\BankLoan\Models\BankPartner;
use App\Domain\BankLoan\Models\RetentionCategory;
use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\Foundation\Tenant\Models\Project;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Setting KPR → Kategori Bank: identitas bank + ketentuan retensi per bank.
 * Mengelola entitas bank_partners yang sama dengan yang dipakai Proses KPR.
 */
class KprBankController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('bankloan.setup.view'), 403);

        $rows = BankPartner::query()
            ->with('project:id,name')
            // retention_coa_count: berapa kategori yang COA Bank-nya sudah di-link-kan —
            // penanda cepat bank mana yang akun Debit-nya sudah otomatis di Approval Retensi.
            ->withCount([
                'retentionTerms',
                'retentionTerms as retention_coa_count' => fn ($q) => $q->whereNotNull('gl_account_id'),
            ])
            ->when($request->input('search'), fn ($q, $s) => $q->where(fn ($w) => $w->where('code', 'like', "%{$s}%")->orWhere('name', 'like', "%{$s}%")->orWhere('account_no', 'like', "%{$s}%")))
            ->when($request->input('project_id'), fn ($q, $v) => $q->where('project_id', $v))
            ->orderBy('name')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('BankLoan/Setup/Bank/Index', [
            'banks'    => $rows,
            'projects' => Project::orderBy('name')->get(['id', 'name']),
            'filters'  => $request->only(['search', 'project_id']),
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()?->can('bankloan.setup.manage'), 403);

        return $this->form(null);
    }

    public function edit(Request $request, BankPartner $bank): Response
    {
        abort_unless($request->user()?->can('bankloan.setup.view'), 403);

        return $this->form($bank->load('retentionTerms.glAccount:id,code,name'));
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('bankloan.setup.manage'), 403);

        $data = $this->validateData($request);
        $bank = BankPartner::create($data['bank']);
        $this->syncTerms($bank, $data['retentions']);

        return redirect()->route('kpr.setup.bank.index')->with('success', "Bank {$bank->name} ditambahkan.");
    }

    public function update(Request $request, BankPartner $bank): RedirectResponse
    {
        abort_unless($request->user()?->can('bankloan.setup.manage'), 403);

        $data = $this->validateData($request);
        $bank->update($data['bank']);
        $this->syncTerms($bank, $data['retentions']);

        return redirect()->route('kpr.setup.bank.index')->with('success', "Bank {$bank->name} diperbarui.");
    }

    public function destroy(Request $request, BankPartner $bank): RedirectResponse
    {
        abort_unless($request->user()?->can('bankloan.setup.manage'), 403);

        $name = $bank->name;
        $bank->delete();

        return back()->with('success', "Bank {$name} dihapus.");
    }

    // ── Helpers ──

    private function form(?BankPartner $bank): Response
    {
        $categories = RetentionCategory::where('is_active', true)->orderBy('sort_order')->orderBy('code')->get(['id', 'code', 'name']);

        $existing = $bank
            ? $bank->retentionTerms->keyBy('retention_category_id')
            : collect();

        return Inertia::render('BankLoan/Setup/Bank/Form', [
            'bank' => $bank ? [
                'id'           => $bank->id,
                'project_id'   => $bank->project_id,
                'code'         => $bank->code,
                'name'         => $bank->name,
                'branch'       => $bank->branch,
                'account_no'   => $bank->account_no,
                'account_name' => $bank->account_name,
                'address'      => $bank->address,
                'city'         => $bank->city,
                'notes'        => $bank->notes,
                'contact_person' => $bank->contact_person,
                'phone'        => $bank->phone,
                'email'        => $bank->email,
                'is_active'    => $bank->is_active,
            ] : null,
            'projects'   => Project::orderBy('name')->get(['id', 'name']),
            'categories' => $categories->map(function ($c) use ($existing) {
                $term = $existing->get($c->id);

                return [
                    'id'            => $c->id,
                    'code'          => $c->code,
                    'name'          => $c->name,
                    'percentage'    => (float) ($term->percentage ?? 0),
                    'amount'        => $term && $term->amount !== null ? (float) $term->amount : null,
                    'notes'         => $term->notes ?? null,
                    'enabled'       => (bool) $term,
                    // COA Bank yang di-link-kan → default akun Debit di Approval Retensi.
                    'gl_account_id' => $term?->gl_account_id,
                ];
            }),
            // Daftar COA untuk dipilih per kategori. Akun bertema Kas/Bank ditandai agar
            // operator mudah menemukannya, tapi semua akun postable tetap boleh dipilih.
            'accounts' => BsAccount::where('is_active', true)->where('is_postable', true)
                ->orderBy('code')->get(['id', 'code', 'name', 'type'])
                ->map(fn ($a) => [
                    'id'      => $a->id,
                    'code'    => $a->code,
                    'name'    => $a->name,
                    'is_bank' => $a->type === 'asset' && (
                        str_contains(mb_strtolower((string) $a->name), 'bank')
                        || str_contains(mb_strtolower((string) $a->name), 'kas')
                    ),
                ])->values(),
        ]);
    }

    /** @return array{bank: array<string,mixed>, retentions: ?array<int,array<string,mixed>>} */
    private function validateData(Request $request): array
    {
        $v = $request->validate([
            'project_id'      => ['nullable', 'integer', 'exists:projects,id'],
            'code'            => ['required', 'string', 'max:32'],
            'name'            => ['required', 'string', 'max:150'],
            'branch'          => ['nullable', 'string', 'max:150'],
            'account_no'      => ['nullable', 'string', 'max:64'],
            'account_name'    => ['nullable', 'string', 'max:150'],
            'address'         => ['nullable', 'string', 'max:255'],
            'city'            => ['nullable', 'string', 'max:100'],
            'notes'           => ['nullable', 'string', 'max:255'],
            'contact_person'  => ['nullable', 'string', 'max:150'],
            'phone'           => ['nullable', 'string', 'max:30'],
            'email'           => ['nullable', 'email', 'max:150'],
            'is_active'       => ['boolean'],
            'retentions'                 => ['nullable', 'array'],
            'retentions.*.category_id'   => ['required', 'integer', 'exists:retention_categories,id'],
            'retentions.*.enabled'       => ['boolean'],
            'retentions.*.percentage'    => ['nullable', 'numeric', 'min:0', 'max:100'],
            'retentions.*.amount'        => ['nullable', 'numeric', 'min:0'],
            'retentions.*.notes'         => ['nullable', 'string', 'max:255'],
            'retentions.*.gl_account_id' => ['nullable', 'integer', 'exists:bs_accounts,id'],
        ]);

        return [
            'bank' => collect($v)->only([
                'project_id', 'code', 'name', 'branch', 'account_no', 'account_name',
                'address', 'city', 'notes', 'contact_person', 'phone', 'email', 'is_active',
            ])->all(),
            // null (bukan []) bila kunci 'retentions' memang tidak dikirim — lihat syncTerms().
            'retentions' => $request->has('retentions') ? ($v['retentions'] ?? []) : null,
        ];
    }

    /**
     * Sinkronkan ketentuan retensi: hanya yang enabled disimpan.
     *
     * $retentions === null berarti klien tidak mengirim daftarnya sama sekali → ketentuan yang
     * ada DIBIARKAN. Tanpa penjaga ini, satu request yang hanya membawa identitas bank akan
     * menghapus seluruh ketentuan retensi berikut tautan COA-nya tanpa jejak. Mengosongkan
     * ketentuan tetap bisa: kirim daftar dengan semua kategori enabled = false.
     */
    private function syncTerms(BankPartner $bank, ?array $retentions): void
    {
        if ($retentions === null) {
            return;
        }

        DB::transaction(function () use ($bank, $retentions): void {
            $bank->retentionTerms()->delete();

            foreach ($retentions as $r) {
                if (! ($r['enabled'] ?? false)) {
                    continue;
                }
                $bank->retentionTerms()->create([
                    'retention_category_id' => (int) $r['category_id'],
                    'gl_account_id'         => ($r['gl_account_id'] ?? null) ? (int) $r['gl_account_id'] : null,
                    'percentage'            => (float) ($r['percentage'] ?? 0),
                    'amount'                => isset($r['amount']) && $r['amount'] !== null && $r['amount'] !== '' ? (float) $r['amount'] : null,
                    'notes'                 => $r['notes'] ?? null,
                ]);
            }
        });
    }
}
