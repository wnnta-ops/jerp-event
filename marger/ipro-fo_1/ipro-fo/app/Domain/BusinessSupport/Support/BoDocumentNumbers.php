<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Support;

/**
 * Katalog penomoran dokumen Back Office — SATU daftar untuk seluruh aplikasi.
 *
 * Dipakai bersama oleh: migrasi yang menanam barisnya, layar Settings → Numerator Back
 * Office, dan BoNumberService yang menerbitkan nomornya. Satu tempat, supaya daftar dokumen
 * di layar tidak pernah berbeda dari daftar dokumen yang sebenarnya bernomor.
 *
 * Format bawaan di sini SENGAJA menghasilkan nomor yang SAMA PERSIS dengan yang dihasilkan
 * kode sebelumnya. Layar ini menjadikan format yang tadinya tertanam di kode bisa dilihat dan
 * diubah — bukan mengubah nomornya pada hari pemasangan.
 *
 * Token yang tersedia (lihat NumeratorService::format):
 *   {PREFIX} {YYYY} {YY} {MM} {DD} {SEQ} {MM_ROMAN} {MMM} {MMM_ID}
 * ditambah token khusus yang dikirim penerbitnya: {KATEGORI} (kode kategori AP) dan
 * {PROJ} (kode proyek pada dokumen Budget Control).
 *
 * CATATAN PENTING soal {YY}/{MM}: nilainya diambil dari TANGGAL DOKUMEN, bukan tanggal hari
 * ini — dokumen bertanggal mundur tetap memakai segmen bulannya sendiri, persis seperti
 * perilaku sebelumnya. Yang memakai tanggal hari ini hanyalah penentuan kapan nomor urutnya
 * kembali ke 1 (reset_strategy).
 */
final class BoDocumentNumbers
{
    /** Scope baris numerator milik Back Office — memisahkannya dari numerator Front Office. */
    public const SCOPE = 'bo';

    /**
     * Seluruh dokumen Back Office yang bernomor.
     *
     * kolom `tabel` & `kolom` dipakai migrasi untuk menanam nomor lanjutan dari data yang
     * SUDAH ADA, sehingga nomor baru tidak pernah menabrak maupun mundur.
     *
     * @return array<string, array{
     *     label: string, grup: string, prefix: string, format: string,
     *     padding: int, reset: string, tabel: ?string, kolom: ?string
     * }>
     */
    public static function all(): array
    {
        $jurnal = fn (string $prefix, string $label) => [
            'label' => $label, 'grup' => 'General Ledger', 'prefix' => $prefix,
            'format' => '{PREFIX}/{YY}{MM}/{SEQ}', 'padding' => 4, 'reset' => 'yearly',
            'tabel' => 'bs_journals', 'kolom' => 'journal_number',
        ];
        $bulanan = fn (string $prefix, string $label, string $grup, string $tabel, string $kolom) => [
            'label' => $label, 'grup' => $grup, 'prefix' => $prefix,
            'format' => '{PREFIX}/{YY}{MM}/{SEQ}', 'padding' => 4, 'reset' => 'yearly',
            'tabel' => $tabel, 'kolom' => $kolom,
        ];
        $jres = fn (string $prefix, string $label, string $grup, string $tabel, string $kolom) => [
            'label' => $label, 'grup' => $grup, 'prefix' => $prefix,
            // "JRES" dulu tertanam di kode StockService — kini terlihat & bisa diubah di layar.
            'format' => '{PREFIX}/JRES/{YY}{MM}/{SEQ}', 'padding' => 4, 'reset' => 'yearly',
            'tabel' => $tabel, 'kolom' => $kolom,
        ];

        return [
            // ── General Ledger ────────────────────────────────────────────────────────
            // Keenamnya DULU berbagi satu deret; kini masing-masing punya deret sendiri.
            'JSA'  => $jurnal('JSA', 'Jurnal Saldo Awal'),
            'JAR'  => $jurnal('JAR', 'Jurnal Piutang (AR)'),
            'JAP'  => $jurnal('JAP', 'Jurnal Hutang (AP)'),
            'JKB'  => $jurnal('JKB', 'Jurnal Kas & Bank'),
            'JINV' => $jurnal('JINV', 'Jurnal Persediaan'),
            'JM'   => $jurnal('JM', 'Jurnal Memorial'),

            // ── Purchasing & Inventory ────────────────────────────────────────────────
            'PO'  => $jres('PO', 'Purchase Order', 'Purchasing', 'bs_purchase_orders', 'po_number'),
            'FPB' => $jres('FPB', 'FPB (Permintaan Barang)', 'Purchasing', 'bs_material_requests', 'mr_number'),
            'RCV' => $jres('RCV', 'Penerimaan Barang', 'Inventory', 'bs_stock_transactions', 'trx_number'),
            'ISS' => $jres('ISS', 'Pengeluaran Barang', 'Inventory', 'bs_stock_transactions', 'trx_number'),

            // ── Account Payable ───────────────────────────────────────────────────────
            'AP.INV' => [
                'label' => 'Invoice Hutang (Registrasi)', 'grup' => 'Account Payable', 'prefix' => 'AP.INV',
                'format' => '{PREFIX}/{KATEGORI}/MUP/{YY}/{MM_ROMAN}/{SEQ}', 'padding' => 5, 'reset' => 'yearly',
                'tabel' => 'bs_ap_invoices', 'kolom' => 'invoice_number',
            ],
            'AP' => $bulanan('AP', 'Invoice Hutang (Posting PO/SPK)', 'Account Payable', 'bs_ap_invoices', 'invoice_number'),
            'AP.KUI' => [
                'label' => 'Kuitansi Hutang', 'grup' => 'Account Payable', 'prefix' => 'AP.KUI',
                'format' => '{PREFIX}/MUP/{YY}/{MM_ROMAN}/{SEQ}', 'padding' => 5, 'reset' => 'yearly',
                'tabel' => 'bs_ap_kuitansis', 'kolom' => 'kuitansi_number',
            ],
            'AP.MEMO' => [
                'label' => 'Memorial Hutang', 'grup' => 'Account Payable', 'prefix' => 'AP.MEMO',
                'format' => '{PREFIX}/MUP/{YY}/{SEQ}', 'padding' => 5, 'reset' => 'yearly',
                'tabel' => 'bs_ap_memorials', 'kolom' => 'memorial_number',
            ],

            // ── Account Receivable ────────────────────────────────────────────────────
            'AR.INV' => [
                'label' => 'Invoice Piutang', 'grup' => 'Piutang Usaha', 'prefix' => 'AR.INV',
                'format' => '{PREFIX}/BR/MUP/{YY}/{MM_ROMAN}/{SEQ}', 'padding' => 5, 'reset' => 'yearly',
                'tabel' => 'bs_ar_invoices', 'kolom' => 'invoice_number',
            ],
            'AR.KUI' => [
                'label' => 'Kuitansi Piutang', 'grup' => 'Piutang Usaha', 'prefix' => 'AR.KUI',
                'format' => '{PREFIX}/MUP/{YY}/{MM_ROMAN}/{SEQ}', 'padding' => 5, 'reset' => 'yearly',
                'tabel' => 'bs_ar_kuitansis', 'kolom' => 'kuitansi_number',
            ],
            'AR.MEMO' => [
                'label' => 'Memorial Piutang', 'grup' => 'Piutang Usaha', 'prefix' => 'AR.MEMO',
                'format' => '{PREFIX}/MUP/{YY}/{SEQ}', 'padding' => 5, 'reset' => 'yearly',
                'tabel' => 'bs_ar_memorials', 'kolom' => 'memorial_number',
            ],

            // ── Cash Book ─────────────────────────────────────────────────────────────
            'BKM' => $bulanan('BKM', 'Bukti Kas/Bank Masuk', 'Cash Book', 'bs_cash_transactions', 'trx_number'),
            'BKK' => $bulanan('BKK', 'Bukti Kas/Bank Keluar', 'Cash Book', 'bs_cash_transactions', 'trx_number'),
            'TRF' => $bulanan('TRF', 'Transfer Antar Kas/Bank', 'Cash Book', 'bs_cash_transactions', 'trx_number'),
            'BON' => $bulanan('BON', 'Bon Sementara', 'Cash Book', 'bs_cash_advances', 'advance_number'),
            'GIRO' => $bulanan('GIRO', 'Giro Masuk', 'Cash Book', 'bs_giros', 'giro_number'),
            'SIP' => [
                'label' => 'SIP (Surat Instruksi Pembayaran)', 'grup' => 'Cash Book', 'prefix' => 'SIP',
                // Satu-satunya yang meletakkan nomor urut di DEPAN.
                'format' => '{SEQ}/FIN/MITRA-SI/{MM_ROMAN}/{YYYY}', 'padding' => 3, 'reset' => 'yearly',
                'tabel' => 'bs_sips', 'kolom' => 'sip_number',
                // Hanya BENTUKNYA yang diatur dari sini. Urutannya tetap dari kolom
                'catatan' => 'Nomor urut SIP tetap dihitung dari kolom print_seq per tahun cetak '
                    .'(dijaga indeks unik), bukan dari nomor urut di layar ini. Yang diatur di sini hanya bentuk nomornya.',
            ],

            // ── Booked Revenue & Contractor ───────────────────────────────────────────
            'BR'  => $bulanan('BR', 'Booked Revenue', 'Booked Revenue', 'bs_booked_revenues', 'br_number'),
            'SPK' => $bulanan('SPK', 'SPK / Work Order', 'Contractor Management', 'bs_work_orders', 'spk_number'),
            'PNP.KPL' => [
                'label' => 'KPL (Kemajuan Pekerjaan Lapangan)', 'grup' => 'Contractor Management', 'prefix' => 'PNP.KPL',
                'format' => '{PREFIX}/MUP/{YY}/{SEQ}', 'padding' => 5, 'reset' => 'yearly',
                'tabel' => 'bs_work_order_progress', 'kolom' => 'kpl_number',
            ],

            // ── Aset Tetap, COGS, Payroll ─────────────────────────────────────────────
            'FA'  => $bulanan('FA', 'Aset Tetap', 'Aset Tetap', 'bs_fixed_assets', 'asset_number'),
            'PAY' => $bulanan('PAY', 'Payroll Run', 'HRGA', 'bs_payroll_runs', 'run_number'),
            'HPP' => $bulanan('HPP', 'Pengakuan HPP', 'COGS', 'bs_cogs_recognitions', 'cogs_number'),
            'COGS' => [
                'label' => 'HPP RealCost / POC', 'grup' => 'COGS', 'prefix' => 'COGS',
                'format' => '{PREFIX}/MUP/{MM}/{YY}/{SEQ}', 'padding' => 5, 'reset' => 'yearly',
                'tabel' => 'bs_cogs_hpp', 'kolom' => 'cogs_id',
            ],
            'CG.SAR' => [
                'label' => 'Realisasi COGS', 'grup' => 'COGS', 'prefix' => 'CG.SAR',
                'format' => '{PREFIX}/{YY}/{SEQ}', 'padding' => 5, 'reset' => 'yearly',
                'tabel' => 'bs_cogs_realizations', 'kolom' => 'doc_no',
            ],
            'CG.MUT' => [
                'label' => 'Mutasi Budget COGS', 'grup' => 'COGS', 'prefix' => 'CG.MUT',
                'format' => '{PREFIX}/{YY}/{SEQ}', 'padding' => 5, 'reset' => 'yearly',
                'tabel' => 'bs_cogs_budget_mutations', 'kolom' => 'doc_no',
            ],

            // ── Budget Control ────────────────────────────────────────────────────────
            'BC' => [
                'label' => 'Master Budget', 'grup' => 'Budget Control', 'prefix' => 'BC',
                'format' => '{PREFIX}/MUP/{PROJ}/{MM}/{YY}/{SEQ}', 'padding' => 5, 'reset' => 'yearly',
                'tabel' => 'bs_budgets', 'kolom' => 'doc_no',
            ],
            'BC.AL' => [
                'label' => 'Alokasi Budget', 'grup' => 'Budget Control', 'prefix' => 'BC.AL',
                'format' => '{PREFIX}/MUP/{PROJ}/{MM}/{YY}/{SEQ}', 'padding' => 5, 'reset' => 'yearly',
                'tabel' => 'bs_budget_allocations', 'kolom' => 'doc_no',
            ],
            'BC.MA' => [
                'label' => 'Koreksi Budget', 'grup' => 'Budget Control', 'prefix' => 'BC.MA',
                'format' => '{PREFIX}/MUP/{PROJ}/{MM}/{YY}/{SEQ}', 'padding' => 5, 'reset' => 'yearly',
                'tabel' => 'bs_budget_corrections', 'kolom' => 'doc_no',
            ],
        ];
    }

    /** @return array<int,string> */
    public static function codes(): array
    {
        return array_keys(self::all());
    }
}
