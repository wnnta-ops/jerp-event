<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsGimmickCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Pastikan SELURUH kolom Rincian Biaya pada Master Data KPR punya Kategori Gimmick —
 * tidak boleh ada yang terlewat.
 *
 * Dua langkah, keduanya idempoten dan TIDAK PERNAH menimpa akun yang sudah disetel operator:
 *  1. Petakan kategori yang sudah ada ke kolom biaya berdasarkan nama/kode (mis. "BIAYA HT
 *     SEBAGIAN" → ht). Hanya kategori yang kpr_biaya_key-nya masih kosong yang disentuh.
 *  2. Buat kategori untuk kolom yang belum punya sama sekali, memakai akun default (lihat
 *     defaultAccounts()). Akunnya WAJIB diperiksa operator di Setup → Kategori Gimmick;
 *     halaman itu menampilkan cakupan per kolom supaya ketahuan mana yang masih default.
 */
class GimmickCategoryKprSeeder extends Seeder
{
    /**
     * Kata kunci pencocokan nama/kode kategori → kolom biaya. Urutan penting: pola yang lebih
     * spesifik didahulukan (BPHTB sebelum HT, kalau tidak "BPHTB" ikut tertangkap pola "HT").
     *
     * @var array<int,array{key:string, match:array<int,string>}>
     */
    private const MATCHERS = [
        ['key' => 'bphtb', 'match' => ['BPHTB']],
        ['key' => 'ppjb',  'match' => ['PPJB']],
        ['key' => 'ajb',   'match' => ['AJB']],
        ['key' => 'ssp',   'match' => ['SSP']],
        ['key' => 'kpr',   'match' => ['KPR']],
        ['key' => 'ht',    'match' => ['HT', 'HAK TANGGUNGAN']],
    ];

    public function run(): void
    {
        $tenantId = (int) (tenant_id() ?? default_tenant_id());

        $existing = BsGimmickCategory::withoutGlobalScopes()->where('tenant_id', $tenantId)->get();

        // 1) Petakan kategori lama yang belum punya kolom biaya.
        foreach ($existing as $cat) {
            if ($cat->kpr_biaya_key !== null) {
                continue;
            }
            $key = $this->matchKey($cat->code.' '.$cat->name);
            if ($key !== null) {
                $cat->update(['kpr_biaya_key' => $key]);
            }
        }

        // 2) Lengkapi kolom yang masih kosong.
        [$debitId, $creditId] = $this->defaultAccounts($tenantId);
        if (! $debitId || ! $creditId) {
            $this->command?->warn('GimmickCategoryKprSeeder: akun default tidak ketemu — kategori baru dilewati.');

            return;
        }

        $covered = BsGimmickCategory::withoutGlobalScopes()->where('tenant_id', $tenantId)
            ->whereNotNull('kpr_biaya_key')->pluck('kpr_biaya_key')->unique()->all();

        foreach (BsGimmickCategory::KPR_BIAYA_COLUMNS as $key => $label) {
            if (in_array($key, $covered, true)) {
                continue;
            }

            // Kalau kodenya sudah dipakai, CUKUP tautkan kolom biayanya — akun yang sudah disetel
            // operator tidak boleh ditimpa nilai default.
            $cat = BsGimmickCategory::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('code', $label)->first();
            if ($cat) {
                $cat->update(['kpr_biaya_key' => $key]);
            } else {
                $cat = BsGimmickCategory::withoutGlobalScopes()->create([
                    'tenant_id' => $tenantId, 'code' => $label, 'name' => $label, 'kpr_biaya_key' => $key,
                    'is_active' => true, 'debit_account_id' => $debitId, 'payable_account_id' => $creditId,
                ]);
            }
            $this->ensureAccountRows($cat, $cat->debit_account_id, $cat->payable_account_id);
        }

        // Kategori lama hasil migrasi bisa saja belum punya baris akun (mis. dibuat manual di DB).
        foreach (BsGimmickCategory::withoutGlobalScopes()->where('tenant_id', $tenantId)->get() as $cat) {
            $this->ensureAccountRows($cat, $cat->debit_account_id, $cat->payable_account_id);
        }
    }

    private function matchKey(string $haystack): ?string
    {
        $hay = ' '.strtoupper(preg_replace('/[^A-Za-z0-9]+/', ' ', $haystack) ?? '').' ';
        foreach (self::MATCHERS as $m) {
            foreach ($m['match'] as $needle) {
                if (str_contains($hay, ' '.$needle.' ')) {
                    return $m['key'];
                }
            }
        }

        return null;
    }

    /**
     * Akun default untuk kategori baru: ikuti akun yang PALING SERING dipakai kategori gimmick
     * yang sudah ada (paling mendekati kebiasaan tenant), baru jatuh ke config gl_accounts.
     *
     * @return array{0:?int, 1:?int}
     */
    private function defaultAccounts(int $tenantId): array
    {
        $mode = fn (string $col) => DB::table('bs_gimmick_categories')->where('tenant_id', $tenantId)
            ->whereNotNull($col)->select($col)->groupBy($col)
            ->orderByRaw('COUNT(*) DESC')->value($col);

        $debit  = $mode('debit_account_id')   ?: BsAccount::where('code', config('business_support.gl_accounts.expense'))->value('id');
        $credit = $mode('payable_account_id') ?: BsAccount::where('code', config('business_support.gl_accounts.payable'))->value('id');

        return [$debit ? (int) $debit : null, $credit ? (int) $credit : null];
    }

    /** Jamin tiap sisi punya minimal satu baris akun (porsi 100%). */
    private function ensureAccountRows(BsGimmickCategory $cat, ?int $debitId, ?int $creditId): void
    {
        foreach ([['debit', $debitId], ['credit', $creditId]] as [$side, $accId]) {
            if (! $accId || $cat->accounts()->where('side', $side)->exists()) {
                continue;
            }
            $cat->accounts()->create(['side' => $side, 'account_id' => $accId, 'portion' => 100, 'sort_order' => 0]);
        }
    }
}
