<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Payable;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsGimmickCategory;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Kategori Gimmick — fungsi sama dengan Kategori Invoice (ApCategoryController),
 * tapi tabelnya sendiri (bs_gimmick_categories).
 *
 * Satu kategori boleh punya BEBERAPA akun debit dan BEBERAPA akun kredit, masing-masing
 * berporsi persen (jumlah per sisi = 100). Kategori juga bisa ditautkan ke satu kolom
 * Rincian Biaya di Master Data KPR agar biaya yang diposting dari FO otomatis muncul di sana.
 */
class GimmickCategoryController extends Controller
{
    /** Toleransi pembulatan saat memeriksa jumlah porsi per sisi. */
    private const PORTION_EPSILON = 0.0001;

    public function index(Request $request): Response
    {
        abort_unless($request->user()?->canModule('ap'), 403);

        $rows = BsGimmickCategory::with(['debitAccount:id,code,name', 'payableAccount:id,code,name', 'accounts.account:id,code,name'])
            ->when($request->input('search'), fn ($q, $s) => $q->where(fn ($w) => $w->where('code', 'like', "%{$s}%")->orWhere('name', 'like', "%{$s}%")))
            ->orderBy('name')->get()
            ->map(function ($c) {
                $side = fn (string $s) => $c->accounts->where('side', $s)->values()->map(fn ($a) => [
                    'account_id' => $a->account_id,
                    'portion'    => (float) $a->portion,
                    'label'      => $a->account ? $a->account->code.' - '.$a->account->name : null,
                ])->all();

                return [
                    'id' => $c->id, 'code' => $c->code, 'name' => $c->name, 'is_active' => $c->is_active,
                    'kpr_biaya_key'   => $c->kpr_biaya_key,
                    'kpr_biaya_label' => $c->kpr_biaya_key ? (BsGimmickCategory::KPR_BIAYA_COLUMNS[$c->kpr_biaya_key] ?? null) : null,
                    // Akun utama (baris pertama tiap sisi) — dipertahankan untuk pembaca lama.
                    'debit_account_id' => $c->debit_account_id, 'payable_account_id' => $c->payable_account_id,
                    'debit'   => $c->debitAccount?->code.' - '.$c->debitAccount?->name,
                    'payable' => $c->payableAccount?->code.' - '.$c->payableAccount?->name,
                    'debits'  => $side('debit'),
                    'credits' => $side('credit'),
                ];
            });

        // Cakupan kolom Rincian Biaya Master Data KPR — supaya ketahuan kalau ada yang terlewat.
        // SENGAJA lepas dari filter pencarian: kalau dihitung dari daftar yang tersaring, mengetik
        // di kotak cari akan membuat kolom yang sebenarnya sudah terpetakan terlihat "belum ada".
        $mapped = BsGimmickCategory::whereNotNull('kpr_biaya_key')
            ->orderBy('code')->get(['code', 'kpr_biaya_key'])->groupBy('kpr_biaya_key');
        $coverage = collect(BsGimmickCategory::KPR_BIAYA_COLUMNS)
            ->map(fn ($label, $key) => [
                'key'        => $key,
                'label'      => $label,
                'categories' => collect($mapped->get($key, []))->pluck('code')->values()->all(),
            ])->values();

        return Inertia::render('BusinessSupport/Payable/GimmickCategories', [
            'categories' => $rows,
            'filters'    => $request->only('search'),
            // Hanya akun transaksi (is_postable) — CoA induk/header tidak ditampilkan.
            'accounts'   => BsAccount::where('is_active', true)->where('is_postable', true)->orderBy('code')->get(['id', 'code', 'name', 'type']),
            'defaultPayable'   => BsAccount::where('code', config('business_support.gl_accounts.payable'))->value('id'),
            'kprBiayaColumns'  => collect(BsGimmickCategory::KPR_BIAYA_COLUMNS)->map(fn ($label, $key) => ['id' => $key, 'label' => $label])->values(),
            'kprBiayaCoverage' => $coverage,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);

        $v = $this->data($request);
        DB::transaction(function () use ($v): void {
            $cat = BsGimmickCategory::create($this->headerOf($v));
            $this->syncAccounts($cat, $v);
        });

        return back()->with('success', 'Kategori gimmick ditambahkan.');
    }

    public function update(Request $request, BsGimmickCategory $category): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);

        $v = $this->data($request, $category->id);
        DB::transaction(function () use ($category, $v): void {
            $category->update($this->headerOf($v));
            $this->syncAccounts($category, $v);
        });

        return back()->with('success', 'Kategori gimmick diperbarui.');
    }

    public function destroy(Request $request, BsGimmickCategory $category): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);
        $category->delete();

        return back()->with('success', 'Kategori gimmick dihapus.');
    }

    /** @return array<string,mixed> */
    private function data(Request $request, ?int $id = null): array
    {
        $v = $request->validate([
            'code'          => ['required', 'string', 'max:40', Rule::unique('bs_gimmick_categories', 'code')->ignore($id)->where('tenant_id', tenant_id())],
            'name'          => ['required', 'string', 'max:150'],
            'kpr_biaya_key' => ['nullable', 'string', Rule::in(array_keys(BsGimmickCategory::KPR_BIAYA_COLUMNS))],
            'is_active'     => ['boolean'],
            // Multi-akun per sisi; menggantikan pasangan akun tunggal yang lama.
            'debits'               => ['required', 'array', 'min:1'],
            'debits.*.account_id'  => ['required', 'integer', 'exists:bs_accounts,id'],
            'debits.*.portion'     => ['required', 'numeric', 'min:0.0001', 'max:100'],
            'credits'              => ['required', 'array', 'min:1'],
            'credits.*.account_id' => ['required', 'integer', 'exists:bs_accounts,id'],
            'credits.*.portion'    => ['required', 'numeric', 'min:0.0001', 'max:100'],
        ]);

        foreach (['debits' => 'Akun Debit', 'credits' => 'Akun Kredit'] as $key => $label) {
            $ids = array_column($v[$key], 'account_id');
            if (count($ids) !== count(array_unique($ids))) {
                throw ValidationException::withMessages([$key => "{$label}: satu akun tidak boleh dipakai dua kali."]);
            }
            $sum = round(array_sum(array_column($v[$key], 'portion')), 4);
            if (abs($sum - 100) > self::PORTION_EPSILON) {
                throw ValidationException::withMessages([$key => "{$label}: jumlah porsi harus 100% (sekarang ".rtrim(rtrim(number_format($sum, 4, ',', '.'), '0'), ',').'%).']);
            }
        }

        // Akun yang sama di kedua sisi membuat jurnalnya saling meniadakan — tolak sejak setup.
        if (array_intersect(array_column($v['debits'], 'account_id'), array_column($v['credits'], 'account_id'))) {
            throw ValidationException::withMessages(['credits' => 'Akun yang sama tidak boleh ada di sisi Debit sekaligus Kredit.']);
        }

        return $v;
    }

    /** Kolom header — akun utama = baris pertama tiap sisi (kompatibilitas pembaca lama). */
    private function headerOf(array $v): array
    {
        return [
            'code'               => $v['code'],
            'name'               => $v['name'],
            'kpr_biaya_key'      => $v['kpr_biaya_key'] ?? null,
            'is_active'          => $v['is_active'] ?? true,
            'debit_account_id'   => (int) $v['debits'][0]['account_id'],
            'payable_account_id' => (int) $v['credits'][0]['account_id'],
        ];
    }

    private function syncAccounts(BsGimmickCategory $category, array $v): void
    {
        $category->accounts()->delete();
        foreach (['debit' => 'debits', 'credit' => 'credits'] as $side => $key) {
            foreach (array_values($v[$key]) as $i => $row) {
                $category->accounts()->create([
                    'side'       => $side,
                    'account_id' => (int) $row['account_id'],
                    'portion'    => round((float) $row['portion'], 4),
                    'sort_order' => $i,
                ]);
            }
        }
    }
}
