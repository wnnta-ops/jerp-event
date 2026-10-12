<?php

declare(strict_types=1);

namespace App\Filament\System\Support;

/**
 * Peta izin BO → MENU BO yang sebenarnya: Modul → Sub-modul → Sub-sub-modul.
 *
 * ── MASALAH YANG DIPECAHKAN ──
 *
 * Matriks Grup Akses BO dulu menyusun barisnya dari POTONGAN NAMA IZIN, sehingga yang
 * terbaca di layar adalah slug mentah: "Sip", "Advance", "Advance Report", "Bankrec",
 * "Cheque Out", "Giro Calendar". Orang yang menyetel peran harus menebak izin mana untuk
 * menu mana — dan tebakan yang salah pada layar hak akses berarti seseorang mendapat
 * wewenang yang tidak dimaksudkan, atau kehilangan menu yang seharusnya ia pegang.
 *
 * Dua cacat lain ikut terbawa: `cashbook.advance` dan `cashbook.advance.report` terpecah
 * jadi dua baris seolah dua menu berbeda (semata karena nama izinnya empat segmen), dan
 * matriksnya hanya dua tingkat padahal sidebar BO tiga.
 *
 * ── DARI MANA ISI TABEL INI ──
 *
 * Bukan karangan. Ditelusuri dari rantai yang memang sudah ada di aplikasi BO:
 *
 *   navGroups.ts  menu  →  nama route
 *   routes/web.php      →  Controller@method
 *   controller          →  izin yang dituntut abort_unless(... can('x'))
 *
 * 79 dari 174 kelompok izin mendapat namanya langsung dari rantai itu. Sisanya layar yang
 * memang tidak dipasang di sidebar (mis. Karyawan & Supplier yang hidup di dalam Employee /
 * Supplier Center) atau izin lintas-layar — itu yang ditulis tangan di bawah, dan sebagian
 * hasil telusur ikut dikoreksi ketika satu kelompok izin menaungi beberapa menu sekaligus
 * (`cashbook.transaction` menaungi Transfer/Kas Masuk/Kas Keluar; memakai nama menu pertama
 * akan menyebutnya "Transfer Rekening" saja).
 *
 * ── KALAU MENUNYA BERUBAH ──
 *
 * Tabel ini yang disunting, satu tempat. Izin yang tidak tercantum di sini TIDAK hilang —
 * ia jatuh ke seksi "Belum dipetakan" pada layarnya. Membuangnya diam-diam berarti izin
 * yang tak bisa diberikan siapa pun dan tak terlihat di layar mana pun.
 */
final class PetaMenuBo
{
    /** Nama seksi penampung izin yang belum punya rumah. */
    public const MODUL_LAINNYA = 'Belum dipetakan ke menu';

    /**
     * Urutan modul — mengikuti sidebar BO. Modul yang tidak tercantum ikut di belakang.
     *
     * COGS Lama & COGS Baru sengaja menjadi SATU modul "COGS": izinnya satu rumpun
     * (`cogs.*`) dan memecahnya berarti menebak izin mana milik model perhitungan yang mana.
     *
     * @var list<string>
     */
    public const URUTAN_MODUL = [
        'Master',
        'Data Center',
        'KPR',
        'Booked Revenue',
        'Aset Tetap',
        'General Ledger',
        'Piutang Usaha (Booked)',
        'Account Payable',
        'Cash Book',
        'Budget Control',
        'Contractor Management',
        'COGS',
        'Purchasing',
        'Inventory',
        'Security',
        'System',
        'Back Office',
    ];

    /**
     * kelompok izin (modul.area) => [Modul, Sub-modul, Nama menu].
     *
     * Sub-modul kosong berarti modulnya memang tidak bertingkat dua di sidebar
     * (Master, Data Center, Inventory, Purchasing, Security, System, Piutang Usaha).
     *
     * @var array<string,array{0:string,1:string,2:string}>
     */
    public const PETA = [
        // ── Master ───────────────────────────────────────────────────────────────────
        'master.agent' => ['Master', '', 'Agen'],
        'master.block' => ['Master', '', 'Blok'],
        'master.cluster' => ['Master', '', 'Cluster'],
        'master.customer' => ['Master', '', 'Customer'],
        'master.customer.view' => ['Master', '', 'Customer — Data Sensitif'],
        'master.pricelist' => ['Master', '', 'Pricelist'],
        'master.sales' => ['Master', '', 'Sales'],
        'master.unit' => ['Master', '', 'Unit'],
        'master.unit.siteplan' => ['Master', '', 'Siteplan Unit'],
        'master.unit.update' => ['Master', '', 'Harga Unit'],
        'master.unit_type' => ['Master', '', 'Jenis Unit'],

        // ── Data Center ──────────────────────────────────────────────────────────────
        // Karyawan & Supplier tidak berdiri di sidebar: keduanya layar di DALAM
        // Employee Center / Supplier Center, dan karena itu tak punya nama menu sendiri.
        'datacenter.hub' => ['Data Center', '', 'Supplier & Employee Center'],
        'datacenter.supplier' => ['Data Center', '', 'Supplier'],
        'datacenter.supplier.category' => ['Data Center', '', 'Kategori Supplier'],
        'datacenter.employee' => ['Data Center', '', 'Karyawan'],
        'datacenter.department' => ['Data Center', '', 'Departemen'],
        'bs.payroll' => ['Data Center', '', 'Payroll'],

        // ── KPR ──────────────────────────────────────────────────────────────────────
        'bankloan.setup' => ['KPR', 'Set Up', 'Set Up KPR'],
        'bankloan.application' => ['KPR', 'Transaction', 'Pengajuan KPR'],
        'bankloan.document' => ['KPR', 'Transaction', 'Berkas KPR'],
        'bankloan.sp3k' => ['KPR', 'Transaction', 'SP3K'],
        'bankloan.akad' => ['KPR', 'Transaction', 'Akad Kredit'],
        'bankloan.disbursement' => ['KPR', 'Transaction', 'Pencairan KPR'],
        'bankloan.retensi' => ['KPR', 'Transaction', 'Retensi KPR'],
        'bankloan.buyback' => ['KPR', 'Transaction', 'Buyback'],

        // ── Booked Revenue ───────────────────────────────────────────────────────────
        'ar.booked-revenue' => ['Booked Revenue', '', 'Sudah Booked Revenue'],
        'ar.umum' => ['Booked Revenue', '', 'Invoice Penjualan'],
        'tax.ppn-keluaran' => ['Booked Revenue', 'Verifikasi Faktur Pajak', 'PPN Keluaran'],
        'tax.ppn-masukan' => ['Booked Revenue', 'Verifikasi Faktur Pajak', 'PPN Masukan'],
        'tax.umum' => ['Booked Revenue', 'Verifikasi Faktur Pajak', 'e-Faktur, NSFP & Rekap'],

        // ── Aset Tetap ───────────────────────────────────────────────────────────────
        'bs.asset' => ['Aset Tetap', 'Registrasi', 'Aset Tetap'],
        'bs.bulk-import' => ['Aset Tetap', 'Registrasi', 'Impor Massal Aset'],
        'bs.overview' => ['Aset Tetap', '', 'Overview Aset Tetap'],
        'bs.projection' => ['Aset Tetap', 'Report / Query', 'Proyeksi Penyusutan'],

        // ── General Ledger ───────────────────────────────────────────────────────────
        'gl.umum' => ['General Ledger', '', 'Masuk General Ledger'],
        'gl.printhub' => ['General Ledger', 'General Function', 'Cetak Dokumen GL'],
        'gl.account' => ['General Ledger', 'Master', 'Chart of Account'],
        'gl.journal' => ['General Ledger', 'Master', 'Jurnal'],
        'gl.journal-dimension' => ['General Ledger', 'Master', 'Dimensi Jurnal'],
        'gl.cost-center' => ['General Ledger', 'Master', 'Cost Center'],
        'gl.cf-mapping' => ['General Ledger', 'Master', 'Mapping Cash Flow Direct'],
        'gl.ledger' => ['General Ledger', 'Query', 'Buku Besar'],
        'gl.bankrec' => ['General Ledger', 'Query', 'Rekonsiliasi Bank'],
        'gl.trialbalance' => ['General Ledger', 'Neraca', 'Neraca Saldo'],
        'gl.financials' => ['General Ledger', 'Neraca', 'Neraca'],
        'gl.worksheet' => ['General Ledger', 'Neraca', 'Neraca Lajur'],
        'gl.comparative' => ['General Ledger', 'Neraca', 'Neraca Komparasi (Bulanan)'],
        'gl.extra-report' => ['General Ledger', 'Neraca', 'Histori Saldo'],
        'gl.cashflow.direct' => ['General Ledger', 'Cash Flow Direct', 'Cash Flow Direct Multi Periode'],
        'gl.cashflow.indirect' => ['General Ledger', 'Cash Flow Indirect', 'Cash Flow Indirect'],
        'gl.late-closing' => ['General Ledger', 'Generate Lap. Keuangan', 'Late Closing'],
        'gl.closing' => ['General Ledger', 'Generate Lap. Perusahaan', 'Closing Fiskal'],
        'gl.period' => ['General Ledger', 'Generate Lap. Perusahaan', 'Buka / Tutup Periode'],
        'gl.saldo-awal' => ['General Ledger', 'Saldo Awal', 'Input Saldo Awal'],
        'gl.cf-category' => ['General Ledger', 'Setup', 'Kategori Cash Flow Direct'],
        'gl.currency' => ['General Ledger', 'Setup', 'Multi Currency'],
        'gl.kurs' => ['General Ledger', 'Setup', 'Kurs Sistem'],
        'gl.transaction-rule' => ['General Ledger', 'Setup', 'Aturan Transaksi'],
        'gl.repair' => ['General Ledger', 'Setup', 'Repair Data'],

        // ── Piutang Usaha (Booked) ───────────────────────────────────────────────────
        'ar.aging' => ['Piutang Usaha (Booked)', '', 'Aging Piutang'],
        'ar.card' => ['Piutang Usaha (Booked)', '', 'Kartu Piutang'],
        'ar.memorial' => ['Piutang Usaha (Booked)', '', 'Memorial Piutang'],
        'ar.kuitansi' => ['Piutang Usaha (Booked)', '', 'Kuitansi Piutang'],
        'ar.penghapusan' => ['Piutang Usaha (Booked)', '', 'Penghapusan Piutang'],
        'ar.piutang-lain' => ['Piutang Usaha (Booked)', '', 'Piutang Lain-lain'],
        'ar.category' => ['Piutang Usaha (Booked)', '', 'Kategori Piutang'],

        // ── Account Payable ──────────────────────────────────────────────────────────
        'ap.umum' => ['Account Payable', 'General Function', 'Cetak & Memo Hutang'],
        'ap.invoice' => ['Account Payable', 'Master', 'Invoice Hutang'],
        'ap.kuitansi' => ['Account Payable', 'Master', 'Kuitansi Hutang'],
        'ap.memorial' => ['Account Payable', 'Master', 'Memorial Hutang'],
        'ap.posting' => ['Account Payable', 'Posting Invoice dari FO', 'Posting Invoice dari FO'],
        'ap.aging' => ['Account Payable', 'Query', 'Aging Hutang'],
        'ap.paycard' => ['Account Payable', 'Query', 'Kartu Hutang'],
        'ap.penghapusan' => ['Account Payable', 'Query', 'Laporan Penghapusan Hutang'],
        'ap.control' => ['Account Payable', 'Query', 'Kontrol Hutang'],
        'tax.pph' => ['Account Payable', 'Query', 'Rekap PPh Dipotong'],
        'ap.opening' => ['Account Payable', 'Saldo Awal', 'Saldo Awal Hutang'],
        'ap.category' => ['Account Payable', 'Setup', 'Kategori Invoice'],
        'ap.berkas' => ['Account Payable', 'Setup', 'Kelengkapan Berkas Tagihan'],
        'ap.costcenter' => ['Account Payable', 'Setup', 'Ubah Cost Center Orang Lain'],
        'ap.repair' => ['Account Payable', 'Setup', 'Repair Data'],

        // ── Cash Book ────────────────────────────────────────────────────────────────
        'cashbook.hub' => ['Cash Book', 'General Function', 'Halaman Muka Cash Book'],
        'cashbook.print' => ['Cash Book', 'General Function', 'Cetak Dokumen Cash Book'],
        'cashbook.repair' => ['Cash Book', 'Setup', 'Repair Data'],
        'cashbook.opening' => ['Cash Book', 'Saldo Awal', 'Saldo Awal Rekening'],
        // Satu kelompok izin untuk Transfer Rekening, Kas Masuk, dan Kas Keluar sekaligus —
        // karena itu namanya tidak boleh salah satu di antaranya.
        'cashbook.transaction' => ['Cash Book', 'Master', 'Transaksi Kas & Bank'],
        'cashbook.ledger' => ['Cash Book', 'Report/Query', 'Rekening Ledger'],
        'cashbook.saldo' => ['Cash Book', 'Report/Query', 'Rekening Saldo'],
        'cashbook.bankrec' => ['Cash Book', 'Report/Query', 'Rekonsiliasi Bank'],
        'cashbook.giro-calendar' => ['Cash Book', 'Report/Query', 'Kalender Cek Giro'],
        'cashbook.advance' => ['Cash Book', 'Transaksi Bon Sementara', 'Bon Sementara'],
        'cashbook.advance.report' => ['Cash Book', 'Transaksi Bon Sementara', 'Laporan Bon Sementara'],
        'cashbook.pettycash' => ['Cash Book', 'Transaksi Kas Kecil', 'Kas Kecil'],
        'cashbook.giro' => ['Cash Book', 'Transaksi Cek Giro Masuk', 'Cek Giro Masuk'],
        'cashbook.cheque-out' => ['Cash Book', 'Transaksi Cek Giro Keluar', 'Cek Giro Keluar'],
        'bs.sip' => ['Cash Book', 'Transaksi Pengeluaran Bank', 'SIP (Instruksi Bayar)'],
        'cashbook.approval-retensi' => ['Cash Book', 'Transaksi Penerimaan Bank', 'Approval Retensi'],
        'cashbook.approval-utjdp' => ['Cash Book', 'Transaksi Penerimaan Bank', 'Approval UM Penjualan'],

        // ── Budget Control ───────────────────────────────────────────────────────────
        'budget.master' => ['Budget Control', 'Master', 'Budget Control'],
        'budget.allocation' => ['Budget Control', 'Master', 'Alokasi Budget Control'],
        'budget.correction' => ['Budget Control', 'Master', 'Koreksi Budget Control'],
        'budget.query' => ['Budget Control', 'Query', 'Query Budget'],
        'budget.report' => ['Budget Control', 'Query', 'Cetak Laporan Budget'],
        'budget.upload' => ['Budget Control', 'Upload Budget', 'Upload Budget'],

        // ── Contractor Management ────────────────────────────────────────────────────
        'contractor.general' => ['Contractor Management', 'General Function', 'Cetak Dokumen SPK'],
        'contractor.spk' => ['Contractor Management', 'Master', 'Surat Perintah Kerja'],
        'contractor.kpl' => ['Contractor Management', 'Master', 'Kontrol Progress Lapangan'],
        'contractor.retention' => ['Contractor Management', 'Master', 'Progress Retensi'],
        'contractor.spk.progress' => ['Contractor Management', 'Surat Perintah Kerja', 'Pembayaran Progress SPK'],
        'contractor.spk.material' => ['Contractor Management', 'Surat Perintah Kerja', 'Pemakaian Material SPK'],
        'contractor.material' => ['Contractor Management', 'Surat Perintah Kerja', 'Permintaan Material'],
        'contractor.mutation' => ['Contractor Management', 'Surat Perintah Kerja', 'Mutasi SPK'],
        'contractor.query' => ['Contractor Management', 'Query', 'Rekap SPK'],
        'contractor.project' => ['Contractor Management', 'Setup', 'Project'],

        // ── COGS ─────────────────────────────────────────────────────────────────────
        // COGS yang berjalan kini memakai izinnya sendiri, `cogs2.*`.
        //
        // Baris `cogs.*` di bawah ini milik COGS LAMA yang ditutup 12 Okt 2026 (izinnya
        // dicabut migrasi BO 2026_10_12_100000). Sengaja DIPERTAHANKAN di peta: pemasangan
        // yang migrasinya belum jalan masih memegang baris izin itu, dan tanpa namanya di
        // sini mereka terlempar ke seksi "Belum dipetakan" — terbaca seperti izin asing,
        // padahal justru yang sedang ditutup. Begitu migrasinya jalan, barisnya hilang
        // sendiri karena peta ini hanya menamai izin yang memang ada di tabel.
        'cogs.general' => ['COGS', 'General Function', 'Cetak Dokumen COGS'],
        'cogs.budget' => ['COGS', 'Setup', 'Budget'],
        'cogs.category' => ['COGS', 'Setup', 'Set Akun Budget'],
        'cogs.location' => ['COGS', 'Setup', 'Lokasi'],
        'cogs.provision' => ['COGS', 'Setup', 'Ketentuan'],
        'cogs.setup.provision' => ['COGS', 'Setup', 'Ketentuan COGS (izin mati)'],
        'cogs.unitcoa' => ['COGS', 'Setup', 'Set COA Jenis Unit'],
        'cogs.upload' => ['COGS', 'Setup', 'Upload Kategori Budget'],
        'cogs.hpp' => ['COGS', 'Master', 'HPP RealCost'],
        'cogs.mutation' => ['COGS', 'Master', 'Mutasi Budget'],
        'cogs.realization' => ['COGS', 'Master', 'Saldo Awal Realisasi'],
        'cogs.recognition' => ['COGS', 'Master', 'Pengakuan HPP'],
        'cogs.component' => ['COGS', 'Komponen COGS', 'Komponen COGS'],
        'cogs.query' => ['COGS', 'Query', 'Report COGS'],
        'cogs.cpp' => ['COGS', 'Query', 'CPP'],
        'cogs2.umum' => ['COGS', '', 'COGS — Perhitungan, Kategori & Actual Costing'],

        // ── Purchasing & Inventory ───────────────────────────────────────────────────
        'bs.fpb' => ['Purchasing', '', 'FPB (Permintaan)'],
        'bs.po' => ['Purchasing', '', 'Purchase Order'],
        'bs.item' => ['Inventory', '', 'Item / Stok'],
        'bs.receipt' => ['Inventory', '', 'Penerimaan Barang'],
        'bs.issue' => ['Inventory', '', 'Pengeluaran Barang'],
        'bs.stockcard' => ['Inventory', '', 'Kartu Stok'],
        'bs.inventory-setup' => ['Inventory', '', 'Setup Inventory'],

        // ── Security & System ────────────────────────────────────────────────────────
        'security.umum' => ['Security', '', 'Security'],
        'system.user' => ['System', '', 'Pengguna'],
        'system.role' => ['System', '', 'Grup Akses'],
        'system.permission' => ['System', '', 'Hak Akses'],
        'system.company' => ['System', '', 'Perusahaan'],
        'system.project' => ['System', '', 'Proyek'],
        'system.tenant' => ['System', '', 'Tenant'],
        'system.audit' => ['System', '', 'Audit Log'],
        'system.setup' => ['System', '', 'Penomoran & Parameter'],

        // ── Izin lintas-modul ────────────────────────────────────────────────────────
        // Bukan menu: inilah kunci masuk Back Office dan wewenang menyetujui yang dipakai
        // Approval Budget, Approval Kas/Bank, dan Approval Jurnal sekaligus.
        'businesssupport.umum' => ['Back Office', '', 'Masuk Back Office'],
        'businesssupport.setup' => ['Back Office', '', 'Mengubah Setup Back Office'],
        'businesssupport.transaction' => ['Back Office', '', 'Membuat Transaksi Back Office'],
        'businesssupport.approval' => ['Back Office', '', 'Wewenang Menyetujui'],
    ];

    /**
     * Baris menu untuk sebuah kelompok izin.
     *
     * @return array{0:string,1:string,2:string} [Modul, Sub-modul, Nama menu]
     */
    public static function untuk(string $kelompok, string $fallbackLabel): array
    {
        return self::PETA[$kelompok] ?? [self::MODUL_LAINNYA, '', $fallbackLabel];
    }

    /** Posisi modul pada urutan sidebar; yang tak dikenal selalu di belakang. */
    public static function urutanModul(string $modul): int
    {
        $i = array_search($modul, self::URUTAN_MODUL, true);

        return $i === false ? PHP_INT_MAX : (int) $i;
    }
}
