<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\BusinessSupport\Models\BsCfCategory;
use App\Domain\BusinessSupport\Models\BsCfFormula;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Isi Set Up Kategori & Set Up Rumus modul Cash Flow — PERSIS mengikuti lampiran
 * "Set Up Kategori dan Set Up Rumus Cashflow.xlsx".
 *
 * Idempoten (updateOrCreate per kode), aman dijalankan berulang.
 *
 * Kategori di luar daftar ini TIDAK dihapus, hanya DINONAKTIFKAN — kalau pernah dipakai
 * membagi mutasi, menghapusnya akan melenyapkan angka laporan bulan-bulan lalu. Yang dibuat
 * sendiri oleh operator (kodenya tidak berpola seeder) dibiarkan apa adanya.
 */
class CashFlowPlanSeeder extends Seeder
{
    /** [section, group, nama] — urutan array = urutan tampil. */
    private const CATEGORIES = [
        // ── KATEGORI INFLOW OPS ──
        ['inflow_ops', null, 'AR COLLECTION CLUSTER 1'],
        ['inflow_ops', null, 'AR COLLECTION CLUSTER 2'],
        ['inflow_ops', null, 'AR COLLECTION CLUSTER 3'],
        ['inflow_ops', null, 'AR COLLECTION CLUSTER 3 T3'],
        ['inflow_ops', null, 'AR COLLECTION CLUSTER 3 T4'],
        ['inflow_ops', null, 'AR COLLECTION CLUSTER 5'],

        // ── KATEGORI INFLOW NON OPS ──
        ['inflow_non_ops', null, 'Setoran Modal'],
        ['inflow_non_ops', null, 'Briding Loan'],
        ['inflow_non_ops', null, 'Bank Loan'],
        ['inflow_non_ops', null, 'Hutang Kyg'],
        ['inflow_non_ops', null, 'Intercompany'],
        ['inflow_non_ops', null, 'Others'],

        // ── KATEGORI OUTFLOW · KONTRAKTOR ──
        ['outflow', 'KONTRAKTOR', 'INFRASTRUKTUR CLUSTER 1'],
        ['outflow', 'KONTRAKTOR', 'INFRASTRUKTUR CLUSTER 2'],
        ['outflow', 'KONTRAKTOR', 'INFRASTRUKTUR CLUSTER 3'],
        ['outflow', 'KONTRAKTOR', 'INFRASTRUKTUR CLUSTER 3 T3'],
        ['outflow', 'KONTRAKTOR', 'INFRASTRUKTUR CLUSTER 3 T4'],
        ['outflow', 'KONTRAKTOR', 'INFRASTRUKTUR CLUSTER 5'],
        ['outflow', 'KONTRAKTOR', 'KONSTRUKSI CLUSTER 1'],
        ['outflow', 'KONTRAKTOR', 'KONSTRUKSI CLUSTER 2'],
        ['outflow', 'KONTRAKTOR', 'KONSTRUKSI CLUSTER 3'],
        ['outflow', 'KONTRAKTOR', 'KONSTRUKSI CLUSTER 3 T3'],
        ['outflow', 'KONTRAKTOR', 'KONSTRUKSI CLUSTER 3 T4'],
        ['outflow', 'KONTRAKTOR', 'KONSTRUKSI CLUSTER 5'],
        ['outflow', 'KONTRAKTOR', 'ESTATE EXPENSE'],

        // ── KATEGORI OUTFLOW · LEGAL & LANDBANK ──
        ['outflow', 'LEGAL & LANDBANK', 'Permit, Sertifikasi Cluster 1'],
        ['outflow', 'LEGAL & LANDBANK', 'Permit, Sertifikasi Cluster 2'],
        ['outflow', 'LEGAL & LANDBANK', 'Permit, Sertifikasi Cluster 5'],
        ['outflow', 'LEGAL & LANDBANK', 'Permit, Sertifikasi Cluster 3'],
        ['outflow', 'LEGAL & LANDBANK', 'Permit, Sertifikasi Cluster 3 T3'],
        ['outflow', 'LEGAL & LANDBANK', 'Permit, Sertifikasi Cluster 3 T4'],
        ['outflow', 'LEGAL & LANDBANK', 'Permit, Sertifikasi Cluster 5 EXT'],
        ['outflow', 'LEGAL & LANDBANK', 'Permit, Sertifikasi Cluster 3 EXT'],
        ['outflow', 'LEGAL & LANDBANK', 'Permit, Sertifikasi Cluster 6'],
        ['outflow', 'LEGAL & LANDBANK', 'Tanah JRK Cluster 1'],
        ['outflow', 'LEGAL & LANDBANK', 'Tanah JRK Cluster 2'],
        ['outflow', 'LEGAL & LANDBANK', 'Tanah JRK Cluster 5'],
        ['outflow', 'LEGAL & LANDBANK', 'Tanah JRK Cluster 3'],
        ['outflow', 'LEGAL & LANDBANK', 'Tanah JRK Cluster 3 T3'],
        ['outflow', 'LEGAL & LANDBANK', 'Tanah JRK Cluster 5 T2'],
        ['outflow', 'LEGAL & LANDBANK', 'Tanah JRK Cluster 3 T4'],
        ['outflow', 'LEGAL & LANDBANK', 'Tanah JRK Cluster 3 T5'],
        ['outflow', 'LEGAL & LANDBANK', 'Tanah JRK Cluster 6'],

        // ── KATEGORI OUTFLOW · SALES & MARKETING ──
        ['outflow', 'SALES & MARKETING', 'Promosi'],
        ['outflow', 'SALES & MARKETING', 'Komisi & BLT'],
        ['outflow', 'SALES & MARKETING', 'Biaya KPR & BPHTB'],

        // ── KATEGORI OUTFLOW · OPERASIONAL ──
        ['outflow', 'OPERASIONAL', 'HRGA (Person Exp, GA, Others*)'],
        ['outflow', 'OPERASIONAL', 'Pajak'],
        ['outflow', 'OPERASIONAL', 'Leasing'],
        ['outflow', 'OPERASIONAL', 'Hutang Bank'],
        ['outflow', 'OPERASIONAL', 'Capex'],
        ['outflow', 'OPERASIONAL', 'Refund Deposit User'],
        ['outflow', 'OPERASIONAL', 'Piutang MUK'],
        ['outflow', 'OPERASIONAL', 'Piutang MUI'],
        ['outflow', 'OPERASIONAL', 'Piutang MNL'],
        ['outflow', 'OPERASIONAL', 'Piutang WTM-AAP'],
        ['outflow', 'OPERASIONAL', 'Piutang MAP'],
        ['outflow', 'OPERASIONAL', 'Deviden'],
        ['outflow', 'OPERASIONAL', 'Briding Loan (Bunga)'],
        ['outflow', 'OPERASIONAL', 'Briding Loan (Pokok)'],
        ['outflow', 'OPERASIONAL', 'Hutang Kyg'],
    ];

    /**
     * [kode, nama, rumus ringkas, penjelasan] — sesuai lampiran SET UP RUMUS.
     * Kode dipakai mesin laporan; nama & penjelasan mengikuti kata-kata di lampiran.
     */
    private const FORMULAS = [
        ['beginning_balance', 'Beginning Balance', 'Ending Balance bulan sebelumnya',
            'ending balance dari bulan sebelumnya. Contoh : ending balance januari 2026 sebesar Rp 5,000,000. Maka Beginning balance Februari 2026 Rp. 5,000,000'],
        ['nett_cashflow', 'nett cashflow', '(total inflow ops)+(total inflow non ops)-(total outflow)',
            '(total inflow ops)+(total inflow non ops)-(total outflow)'],
        ['ending_balance', 'Ending Balance', 'beginning balance + nett cashflow',
            'beginning balance + nett cashflow'],
        ['aktual', 'aktual', 'Σ Pembagian Cash Flow pada bulan bersangkutan',
            'angka aktual per kategori pada bulan bersangkutan yg didapatkan setelah ada klasifikasi transaksi bank/kas'],
        ['proyeksi', 'proyeksi', 'Input proyeksi cashplan per kategori/bulan',
            'angka proyeksi didapatkan dari proyeksi pada cashplan'],
        ['wnpb', 'WNPB', 'Input WPNB per kategori/tahun bersangkutan',
            'angka proyeksi per kategori pada WPNB tahun bersangkutan'],
        ['gap', 'gap', 'angka proyeksi - angka aktual',
            'angka proyeksi - angka aktual'],
        ['total_ytd', 'total 2026', 'Σ aktual Januari s/d bulan berjalan (tahun dipilih)',
            'total actual per kategori dari januari 2026 sampai bulan berjalan 2026'],
        ['total_multi_year', 'total 2022-2026', 'Σ aktual Januari 2022 s/d bulan berjalan',
            'total actual per kategori dari januari 2022 sampai bulan berjalan 2026'],
    ];

    public function up(): void { $this->run(); }

    public function run(): void
    {
        $tenantId = (int) (tenant_id() ?? default_tenant_id());

        $codes = [];
        $order = 0;
        foreach (self::CATEGORIES as [$section, $group, $name]) {
            $order += 10;
            $code = $this->codeOf($section, $group, $name);
            $codes[] = $code;
            BsCfCategory::withoutGlobalScopes()->updateOrCreate(
                ['tenant_id' => $tenantId, 'code' => $code],
                ['section' => $section, 'group_name' => $group, 'name' => $name, 'sort_order' => $order, 'is_active' => true],
            );
        }

        // Kategori bawaan seeder LAMA (berpola kode IO-/IN-/OF-) yang tidak ada di lampiran:
        //  - belum pernah dipakai membagi mutasi → DIHAPUS, supaya halaman Set Up bersih dan
        //    persis lampiran, tidak tersisa puluhan baris nonaktif yang membingungkan;
        //  - sudah pernah dipakai → hanya DINONAKTIFKAN, karena menghapusnya akan melenyapkan
        //    angka laporan bulan-bulan yang terlanjur memakainya.
        // Kategori buatan operator sendiri (kode di luar pola seeder) tidak disentuh sama sekali.
        $stale = BsCfCategory::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereNotIn('code', $codes)
            ->where(fn ($q) => $q->where('code', 'like', 'IO-%')->orWhere('code', 'like', 'IN-%')->orWhere('code', 'like', 'OF-%'))
            ->get();

        $dipakai = DB::table('bs_ledger_cluster_splits')->whereNotNull('cf_category_id')
            ->distinct()->pluck('cf_category_id')->map(fn ($v) => (int) $v)->all();

        $hapus = 0;
        $nonaktif = 0;
        foreach ($stale as $cat) {
            if (in_array((int) $cat->id, $dipakai, true)) {
                if ($cat->is_active) {
                    $cat->update(['is_active' => false]);
                    $nonaktif++;
                }

                continue;
            }
            $cat->forceDelete();
            $hapus++;
        }
        if ($hapus || $nonaktif) {
            $this->command?->warn("Kategori lama di luar lampiran: {$hapus} dihapus (belum terpakai), {$nonaktif} dinonaktifkan (sudah terpakai).");
        }

        $fOrder = 0;
        foreach (self::FORMULAS as [$code, $name, $expr, $desc]) {
            $fOrder += 10;
            BsCfFormula::withoutGlobalScopes()->updateOrCreate(
                ['tenant_id' => $tenantId, 'code' => $code],
                ['name' => $name, 'expression' => $expr, 'description' => $desc, 'sort_order' => $fOrder],
            );
        }

        $this->command?->info(count(self::CATEGORIES).' kategori & '.count(self::FORMULAS).' rumus cash flow disiapkan (tenant '.$tenantId.').');
    }

    /**
     * Kode stabil dari seksi+grup+nama agar seeder idempoten walau urutan daftar berubah.
     *
     * Sufiks hash WAJIB: banyak nama hanya berbeda di ekornya ("… Cluster 3 T3" vs "… Cluster 3 T4"
     * vs "… Cluster 3 EXT"). Tanpa hash, kode yang dipangkas akan bertabrakan dan updateOrCreate
     * diam-diam menimpa kategori lain — kategorinya jadi hilang tanpa pesan error.
     */
    private function codeOf(string $section, ?string $group, string $name): string
    {
        $slug = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '-', trim($name)) ?? '');
        $pre  = match ($section) {
            'inflow_ops'     => 'IO',
            'inflow_non_ops' => 'IN',
            default          => 'OF',
        };
        $hash = substr(md5($section.'|'.($group ?? '').'|'.$name), 0, 6);

        return substr($pre.'-'.trim($slug, '-'), 0, 50).'-'.$hash;
    }
}
