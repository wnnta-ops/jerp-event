<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Setup;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsCashFlowCategory;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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
            ->get(['id', 'code', 'category_code', 'name', 'type', 'level', 'parent_id', 'parent_code', 'normal_balance', 'is_postable', 'is_active', 'cf_direct_category_id', 'cf_indirect_category_id']);

        $cfCats = fn (string $kind) => BsCashFlowCategory::where('kind', $kind)->where('is_active', true)
            ->orderBy('sort_order')->orderBy('code')->get(['id', 'code', 'name', 'activity']);

        return Inertia::render('BusinessSupport/Setup/Accounts', [
            'accounts'    => $rows,
            'types'       => BsAccount::TYPES,
            // Nama tingkatan datang dari model, bukan disalin ulang di layar: satu daftar,
            // satu tempat mengubahnya, dan pesan galat server memakai nama yang sama.
            'levels'      => BsAccount::LEVELS,
            // Calon induk untuk tiap level — seluruh akun, tidak disaring pencarian daftar,
            // supaya operator yang sedang menyaring tabel tetap bisa memilih induk mana pun.
            'parentOptions' => BsAccount::orderBy('code')->get(['id', 'code', 'name', 'level'])
                ->map(fn ($a) => ['id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'level' => (int) $a->level])->values(),
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
            // Level dipilih operator, tidak lagi diterka dari panjang kode. Menerkanya membuat
            // kode sepanjang berapa pun yang tidak ganjil/≤11 diam-diam jatuh ke level 6 —
            // akun header bisa lahir sebagai Detil Sub Transaksi tanpa seorang pun diberi tahu.
            'level'          => ['required', 'integer', 'between:1,'.count(BsAccount::LEVELS)],
            // Induk WAJIB kecuali level 1: itulah yang membuat bagan akun benar-benar bertingkat,
            // bukan sekadar terlihat bertingkat karena kodenya kebetulan berawalan sama.
            'parent_id'      => [Rule::requiredIf(fn () => (int) $request->input('level') > 1), 'nullable', 'integer', 'exists:bs_accounts,id'],
            'type'           => ['required', Rule::in(array_keys(BsAccount::TYPES))],
            'normal_balance' => ['required', Rule::in(['D', 'K'])],
            'is_postable'    => ['boolean'],
            'is_active'      => ['boolean'],
            'cf_direct_category_id'   => ['nullable', 'integer', Rule::exists('bs_cash_flow_categories', 'id')->where('kind', 'direct')],
            'cf_indirect_category_id' => ['nullable', 'integer', Rule::exists('bs_cash_flow_categories', 'id')->where('kind', 'indirect')],
        ]);

        $level  = (int) $data['level'];
        $parent = $data['parent_id'] ? BsAccount::find($data['parent_id']) : null;
        $galat  = [];

        // Induk harus TEPAT satu tingkat di atas — bukan sekadar ada. Ledger yang beribu Kategori
        // (melompati Sub Kategori) akan membuat penjumlahan bertingkat kehilangan satu lapis.
        if ($level === 1 && $parent) {
            $galat['parent_id'] = 'Akun level 1 ('.BsAccount::LEVELS[1].') adalah puncak bagan akun — ia tidak boleh punya induk.';
        } elseif ($level > 1 && (! $parent || (int) $parent->level !== $level - 1)) {
            $galat['parent_id'] = 'Induk harus akun berlevel '.($level - 1).' ('.BsAccount::LEVELS[$level - 1].')'
                .($parent ? ', sedangkan yang dipilih berlevel '.$parent->level.' ('.(BsAccount::LEVELS[(int) $parent->level] ?? '?').').' : '.');
        }

        // Panjang kode ditentukan levelnya: tiap turun satu tingkat menambah dua digit.
        $panjang = 2 * $level - 1;
        if (! ctype_digit($data['code'])) {
            $galat['code'] = 'Kode akun hanya boleh berisi angka.';
        } elseif (strlen($data['code']) !== $panjang) {
            $galat['code'] = 'Kode akun level '.$level.' ('.BsAccount::LEVELS[$level].') harus '.$panjang.' digit, bukan '.strlen($data['code']).'.';
        } elseif ($parent && ! str_starts_with($data['code'], (string) $parent->code)) {
            // Kode anak MELANJUTKAN kode induknya. Tanpa syarat ini, akun bisa terdaftar di
            // bawah induk yang satu tetapi terbaca milik cabang lain oleh apa pun yang
            // menyusun hierarki dari awalan kode — dan di aplikasi ini itu banyak.
            $galat['code'] = 'Kode akun harus melanjutkan kode induknya, yaitu diawali "'.$parent->code.'".';
        }

        // Akun yang sudah punya anak tidak boleh dipindah levelnya: anaknya akan tergantung.
        if ($id && $level !== (int) (BsAccount::find($id)?->level ?? $level)
            && BsAccount::where('parent_id', $id)->orWhere('parent_code', BsAccount::find($id)?->code)->exists()) {
            $galat['level'] = 'Akun ini sudah punya akun turunan — levelnya tidak bisa diubah sebelum turunannya dipindahkan.';
        }

        if ($galat) {
            throw ValidationException::withMessages($galat);
        }

        unset($data['parent_id']);
        $data['parent_id']     = $parent?->id;
        $data['parent_code']   = $parent?->code;
        $data['category_code'] = BsAccount::categoryOf($data['code']);

        return $data;
    }
}
