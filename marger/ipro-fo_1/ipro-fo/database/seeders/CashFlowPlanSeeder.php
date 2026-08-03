<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\BusinessSupport\Models\BsCfCategory;
use App\Domain\BusinessSupport\Models\BsCfFormula;
use Illuminate\Database\Seeder;

/**
 * Isi awal modul Cash Flow: kategori & rumus PERSIS mengikuti lampiran
 * "SET UP KATEGORI" dan "SET UP RUMUS".
 *
 * Idempoten (updateOrCreate per kode), jadi aman dijalankan berulang dan tidak menimpa
 * kategori tambahan yang dibuat operator sendiri.
 */
class CashFlowPlanSeeder extends Seeder
{
    /** [section, group, nama] — urutan array = urutan tampil. */
    private const CATEGORIES = [
        // ── CASH IN OPS ──
        ["inflow_ops", null, "AR Collection Cluster 1"],
        ["inflow_ops", null, "AR Collection Cluster 2"],
        ["inflow_ops", null, "AR Collection Cluster 5"],
        ["inflow_ops", null, "AR Collection Cluster 3"],
        ["inflow_ops", null, "AR Collection cluster 3 T3"],
        ["inflow_ops", null, "AR Collection cluster 3 T4"],
        ["inflow_ops", null, "AR Collection cluster 5 Ext"],
        ["inflow_ops", null, "AR Collection cluster 3 Ext"],
        ["inflow_ops", null, "AR Collection cluster 6"],
        ["inflow_ops", null, "Refund UTJ,DP,KPR"],
        // ── CASH OUT · KONTRAKTOR ──
        ["outflow", "KONTRAKTOR", "Infra Cluster 1"],
        ["outflow", "KONTRAKTOR", "Infra Cluster 2"],
        ["outflow", "KONTRAKTOR", "Infra Cluster 5"],
        ["outflow", "KONTRAKTOR", "Infra Cluster 3"],
        ["outflow", "KONTRAKTOR", "Infra cluster 3 T3"],
        ["outflow", "KONTRAKTOR", "Infra cluster 3 T4"],
        ["outflow", "KONTRAKTOR", "Infra cluster 5 Ext"],
        ["outflow", "KONTRAKTOR", "Infra cluster 3 Ext"],
        ["outflow", "KONTRAKTOR", "Infra cluster 6"],
        ["outflow", "KONTRAKTOR", "Konstruksi Cluster 1"],
        ["outflow", "KONTRAKTOR", "Konstruksi Cluster 2"],
        ["outflow", "KONTRAKTOR", "Konstruksi Cluster 5"],
        ["outflow", "KONTRAKTOR", "Konstruksi Cluster 3"],
        ["outflow", "KONTRAKTOR", "Konstruksi cluster 3 T3"],
        ["outflow", "KONTRAKTOR", "Konstruksi cluster 3 T4"],
        ["outflow", "KONTRAKTOR", "Konstruksi cluster 5 Ext"],
        ["outflow", "KONTRAKTOR", "Konstruksi cluster 3 Ext"],
        ["outflow", "KONTRAKTOR", "Konstruksi cluster 6"],
        ["outflow", "KONTRAKTOR", "Estate Expenses"],
        // ── CASH OUT · LEGAL & LANDBANK ──
        ["outflow", "LEGAL & LANDBANK", "Permit, Sertifikasi Cluster 1"],
        ["outflow", "LEGAL & LANDBANK", "Permit, Sertifikasi Cluster 2"],
        ["outflow", "LEGAL & LANDBANK", "Permit, Sertifikasi Cluster 5"],
        ["outflow", "LEGAL & LANDBANK", "Permit, Sertifikasi Cluster 3"],
        ["outflow", "LEGAL & LANDBANK", "Permit, Sertifikasi Cluster 3 T3"],
        ["outflow", "LEGAL & LANDBANK", "Permit, Sertifikasi Cluster 3 T4"],
        ["outflow", "LEGAL & LANDBANK", "Permit, Sertifikasi Cluster 5 EXT"],
        ["outflow", "LEGAL & LANDBANK", "Permit, Sertifikasi Cluster 3 EXT"],
        ["outflow", "LEGAL & LANDBANK", "Permit, Sertifikasi Cluster 6"],
        ["outflow", "LEGAL & LANDBANK", "Tanah JRK Cluster 1"],
        ["outflow", "LEGAL & LANDBANK", "Tanah JRK Cluster 2"],
        ["outflow", "LEGAL & LANDBANK", "Tanah JRK Cluster 5"],
        ["outflow", "LEGAL & LANDBANK", "Tanah JRK Cluster 3"],
        ["outflow", "LEGAL & LANDBANK", "Tanah JRK Cluster 3 T3"],
        ["outflow", "LEGAL & LANDBANK", "Tanah JRK Cluster 5 EXT"],
        ["outflow", "LEGAL & LANDBANK", "Tanah JRK Cluster 3 T4"],
        ["outflow", "LEGAL & LANDBANK", "Tanah JRK Cluster 3 EXT (3,5Ha)"],
        ["outflow", "LEGAL & LANDBANK", "Tanah JRK Cluster 6"],
        // ── CASH OUT · SALES & MARKETING ──
        ["outflow", "SALES & MARKETING", "Promosi"],
        ["outflow", "SALES & MARKETING", "Komisi & BLT"],
        ["outflow", "SALES & MARKETING", "Biaya KPR & BPHTB"],
        // ── CASH OUT · OPERASIONAL ──
        ["outflow", "OPERASIONAL", "HRGA (Person Exp, GA, Others*)"],
        ["outflow", "OPERASIONAL", "Pajak"],
        ["outflow", "OPERASIONAL", "Leasing"],
        ["outflow", "OPERASIONAL", "Hutang Bank"],
        ["outflow", "OPERASIONAL", "Capex"],
        ["outflow", "OPERASIONAL", "Refund Deposit User"],
        ["outflow", "OPERASIONAL", "Piutang MUK"],
        ["outflow", "OPERASIONAL", "Piutang MUI"],
        ["outflow", "OPERASIONAL", "Piutang MNL"],
        ["outflow", "OPERASIONAL", "Piutang WTM-AAP"],
        ["outflow", "OPERASIONAL", "Piutang MAP"],
        ["outflow", "OPERASIONAL", "Deviden"],
        ["outflow", "OPERASIONAL", "Briding Loan (Bunga)"],
        ["outflow", "OPERASIONAL", "Briding Loan (Pokok)"],
        ["outflow", "OPERASIONAL", "Hutang Kyg"],
        // ── CASH IN NON OPS ──
        ["inflow_non_ops", null, "Setoran Modal"],
        ["inflow_non_ops", null, "Briding Loan"],
        ["inflow_non_ops", null, "Bank Loan"],
        ["inflow_non_ops", null, "Hutang Kyg"],
        ["inflow_non_ops", null, "Intercompany"],
        ["inflow_non_ops", null, "Others"],
    ];

    /** [kode, nama, rumus ringkas, penjelasan] — sesuai lampiran SET UP RUMUS. */
    private const FORMULAS = [
        ['beginning_balance', 'Beginning Balance', 'Ending Balance bulan sebelumnya',
            'Ending balance dari bulan sebelumnya. Contoh: ending balance Januari 2026 sebesar Rp 5.000.000, maka Beginning Balance Februari 2026 Rp 5.000.000.'],
        ['nett_cashflow', 'Nett Cashflow', '(Total Inflow Ops) + (Total Inflow Non Ops) − (Total Outflow)',
            'Selisih seluruh kas masuk terhadap kas keluar pada bulan bersangkutan.'],
        ['ending_balance', 'Ending Balance', 'Beginning Balance + Nett Cashflow',
            'Saldo akhir kas pada bulan bersangkutan; menjadi Beginning Balance bulan berikutnya.'],
        ['aktual', 'Aktual', 'Σ Pembagian Cash Flow pada bulan bersangkutan',
            'Angka aktual per kategori pada bulan bersangkutan, didapat setelah ada klasifikasi transaksi bank/kas (Pembagian Cash Flow pada Rekening Ledger).'],
        ['proyeksi', 'Proyeksi', 'Input proyeksi cashplan per kategori/bulan',
            'Angka proyeksi didapatkan dari proyeksi pada cashplan.'],
        ['wnpb', 'WNPB', 'Input WNPB per kategori/tahun bersangkutan',
            'Angka proyeksi per kategori pada WNPB tahun bersangkutan.'],
        ['gap', 'Gap', 'Proyeksi − Aktual',
            'Selisih antara angka proyeksi dengan angka aktual.'],
        ['total_ytd', 'Total Tahun Berjalan', 'Σ Aktual Januari s/d bulan berjalan (tahun dipilih)',
            'Total aktual per kategori dari Januari tahun berjalan sampai bulan berjalan.'],
        ['total_multi_year', 'Total 2022–Sekarang', 'Σ Aktual Januari 2022 s/d bulan berjalan',
            'Total aktual per kategori dari Januari 2022 sampai bulan berjalan.'],
    ];

    public function up(): void { $this->run(); }

    public function run(): void
    {
        $tenantId = (int) (tenant_id() ?? default_tenant_id());

        $order = 0;
        foreach (self::CATEGORIES as [$section, $group, $name]) {
            $order += 10;
            BsCfCategory::withoutGlobalScopes()->updateOrCreate(
                ['tenant_id' => $tenantId, 'code' => $this->codeOf($section, $group, $name)],
                ['section' => $section, 'group_name' => $group, 'name' => $name, 'sort_order' => $order, 'is_active' => true],
            );
        }

        $fOrder = 0;
        foreach (self::FORMULAS as [$code, $name, $expr, $desc]) {
            $fOrder += 10;
            BsCfFormula::withoutGlobalScopes()->updateOrCreate(
                ['tenant_id' => $tenantId, 'code' => $code],
                ['name' => $name, 'expression' => $expr, 'description' => $desc, 'sort_order' => $fOrder],
            );
        }
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
