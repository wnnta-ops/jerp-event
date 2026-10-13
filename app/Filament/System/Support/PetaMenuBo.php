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
        'ap.reimburse' => ['Account Payable', 'Master', 'Reimburse Biaya Karyawan'],
        'ap.deposit' => ['Account Payable', 'Master', 'Deposit Renovasi Customer'],
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
     * Izin TAMPIL (`menu.*`) => [Modul, Sub-modul, Nama menu] — satu butir sidebar BO per baris.
     *
     * Izin ini tidak membuka layar apa pun; ia hanya menentukan butir sidebar mana yang muncul
     * (izin aksinya tetap harus dipegang). Di matriks, centangnya menumpang di baris menu yang
     * namanya sama, sehingga "Invoice Hutang" berisi Tampil + Lihat/Buat/… dalam satu baris.
     *
     * Disalin dari config/menu_permissions.php milik BO — aplikasi ini tidak bisa membaca config
     * aplikasi lain. Menambah butir di sana berarti menambah barisnya di sini; yang terlupa
     * tidak hilang, ia jatuh ke seksi "Belum dipetakan".
     *
     * @var array<string,array{0:string,1:string,2:string}>
     */
    public const PETA_TAMPIL = [
        // ── Account Payable ────────────────────────────────────────────────────────────
        'menu.bs.ap.create' => ['Account Payable', 'Generate Invoice', 'Registrasi Invoice Hutang'],
        'menu.bs.ap.index' => ['Account Payable', 'Master', 'Invoice Hutang'],
        'menu.bs.ap.kuitansi.index' => ['Account Payable', 'Master', 'Kuitansi Hutang'],
        'menu.bs.ap.reimburse.index' => ['Account Payable', 'Master', 'Reimburse Biaya Karyawan'],
        'menu.bs.ap.deposit.index' => ['Account Payable', 'Master', 'Deposit Renovasi Customer'],
        'menu.bs.ap.penghapusan.index' => ['Account Payable', 'Penyesuaian Invoice', 'Penghapusan Nilai Hutang'],
        'menu.bs.ap.posting.spk.index' => ['Account Payable', 'Posting Invoice SPK', 'Posting Invoice SPK'],
        'menu.bs.ap.barang-siap-jual.index' => ['Account Payable', 'Posting Invoice SPK', 'Posting ke Barang Siap Dijual'],
        'menu.bs.ap.posting.index' => ['Account Payable', 'Posting Invoice dari FO', 'Posting Invoice dari FO'],
        'menu.bs.ap.posting.po.index' => ['Account Payable', 'Posting Invoice dari Purchase Order', 'Posting Invoice dari Purchase Order'],
        'menu.bs.ap.aging.detail' => ['Account Payable', 'Query', 'Aging Hutang'],
        'menu.bs.ap.aging-supplier' => ['Account Payable', 'Query', 'Aging Hutang Supplier'],
        'menu.bs.ap.card' => ['Account Payable', 'Query', 'Kartu Hutang'],
        'menu.bs.ap.payment-card' => ['Account Payable', 'Query', 'Kartu Pembayaran Hutang'],
        'menu.bs.ap.report' => ['Account Payable', 'Query', 'Laporan Invoice Hutang'],
        'menu.bs.ap.penghapusan.report' => ['Account Payable', 'Query', 'Laporan Penghapusan Hutang'],
        'menu.bs.projection.hutang' => ['Account Payable', 'Query', 'Proyeksi Hutang'],
        'menu.bs.tax.pph' => ['Account Payable', 'Query', 'Rekap PPh Dipotong'],
        'menu.bs.ap.opening.index' => ['Account Payable', 'Saldo Awal', 'Saldo Awal'],
        'menu.bs.gimmick.categories.index' => ['Account Payable', 'Setup', 'Kategori Gimmick'],
        'menu.bs.ap.categories.index' => ['Account Payable', 'Setup', 'Kategori Invoice'],
        'menu.bs.ap.repair.index' => ['Account Payable', 'Setup', 'Repair Data'],

        // ── Aset Tetap ─────────────────────────────────────────────────────────────────
        'menu.bs.fa.create' => ['Aset Tetap', 'Registrasi', 'Registrasi Aset'],
        'menu.bs.fa.run' => ['Aset Tetap', 'Registrasi', 'Registrasi Penyusutan'],
        'menu.bs.bulk.form.asset' => ['Aset Tetap', 'Registrasi', 'Upload Aset Massal'],
        'menu.bs.fa.penjualan' => ['Aset Tetap', 'Report / Query', 'Penjualan Aset'],
        'menu.bs.fa.summary' => ['Aset Tetap', 'Report / Query', 'Summary Aset'],

        // ── Booked Revenue ─────────────────────────────────────────────────────────────
        'menu.bs.tax.keluaran' => ['Booked Revenue', 'Verifikasi Faktur Pajak', 'PPN Keluaran'],
        'menu.bs.tax.masukan' => ['Booked Revenue', 'Verifikasi Faktur Pajak', 'PPN Masukan'],

        // ── Budget Control ─────────────────────────────────────────────────────────────
        'menu.bs.budget.allocation.create' => ['Budget Control', 'Alokasi Budget', 'Alokasi Budget'],
        'menu.bs.budget.allocation.approvals' => ['Budget Control', 'Alokasi Budget', 'Approval Alokasi Budget'],
        'menu.bs.budget.correction.approvals' => ['Budget Control', 'Koreksi Budget', 'Approval Koreksi Budget'],
        'menu.bs.budget.correction.create' => ['Budget Control', 'Koreksi Budget', 'Koreksi Budget'],
        'menu.bs.budget.allocation.index' => ['Budget Control', 'Master', 'Alokasi Budget Control'],
        'menu.bs.budget.index' => ['Budget Control', 'Master', 'Budget Control'],
        'menu.bs.budget.correction.index' => ['Budget Control', 'Master', 'Koreksi Budget Control'],
        'menu.bs.budget.query.allocation' => ['Budget Control', 'Query', 'Alokasi Budget'],
        'menu.bs.budget.query.correction' => ['Budget Control', 'Query', 'Koreksi Budget'],
        'menu.bs.budget.query.master' => ['Budget Control', 'Query', 'Master Budget'],
        'menu.bs.budget.query.realization' => ['Budget Control', 'Query', 'Realisasi Budget'],
        'menu.bs.budget.query.report' => ['Budget Control', 'Query', 'Report Budget'],
        'menu.bs.budget.approvals' => ['Budget Control', 'Upload Budget', 'Approval Budget'],
        'menu.bs.budget.upload.index' => ['Budget Control', 'Upload Budget', 'Upload Budget'],

        // ── COGS ───────────────────────────────────────────────────────────────────────
        'menu.bs.cogs2.buka.actual.housing' => ['COGS', 'Actual Costing COGS', 'Actual Costing COGS-Housing'],
        'menu.bs.cogs2.buka.actual.infra' => ['COGS', 'Actual Costing COGS', 'Actual Costing COGS-Infra'],
        'menu.bs.cogs2.buka.actual.license' => ['COGS', 'Actual Costing COGS', 'Actual Costing COGS-License'],
        'menu.bs.cogs2.buka.actual.tanah' => ['COGS', 'Actual Costing COGS', 'Actual Costing COGS-Tanah'],
        'menu.bs.cogs2.index' => ['COGS', 'Perhitungan COGS', 'Daftar Periode'],
        'menu.bs.cogs2.buka.perhitungan' => ['COGS', 'Perhitungan COGS', 'Perhitungan COGS'],
        'menu.bs.cogs2.buka.rincian' => ['COGS', 'Rincian COGS', 'Rincian COGS'],
        'menu.bs.cogs2.buka.kategori.housing' => ['COGS', 'Set Up Kategori COGS', 'Set Up Kategori COGS-Housing'],
        'menu.bs.cogs2.buka.kategori.infra' => ['COGS', 'Set Up Kategori COGS', 'Set Up Kategori COGS-Infra'],
        'menu.bs.cogs2.buka.kategori.license' => ['COGS', 'Set Up Kategori COGS', 'Set Up Kategori COGS-License'],
        'menu.bs.cogs2.buka.kategori.tanah' => ['COGS', 'Set Up Kategori COGS', 'Set Up Kategori COGS-Tanah'],

        // ── Cash Book ──────────────────────────────────────────────────────────────────
        'menu.bs.advance.allocation.index' => ['Cash Book', 'Bon Sementara (Finance)', 'Alokasi Bon Sementara Finance'],
        'menu.bs.advance.payments' => ['Cash Book', 'Bon Sementara (Finance)', 'Bon Sementara Finance'],
        'menu.bs.advance.index.1' => ['Cash Book', 'Bon Sementara (Finance)', 'Realisasi Bon Sementara Finance'],
        'menu.bs.advance.cancelled' => ['Cash Book', 'Bon Sementara (Finance)', 'Transaksi Bon Batal (Riwayat)'],
        'menu.bs.cashflow.report' => ['Cash Book', 'Cash Flow', 'Report Cashflow'],
        'menu.bs.cashflow.categories' => ['Cash Book', 'Cash Flow', 'Set Up Kategori'],
        'menu.bs.cashflow.formulas' => ['Cash Book', 'Cash Flow', 'Set Up Rumus'],
        'menu.bs.cashbook.printhub' => ['Cash Book', 'General Function', 'General Function'],
        'menu.bs.cheque-out.index.master' => ['Cash Book', 'Master', 'Cek Giro Keluar'],
        'menu.bs.giro.index.master' => ['Cash Book', 'Master', 'Cek Giro Masuk'],
        'menu.bs.pettycash.index.out' => ['Cash Book', 'Master', 'Kas Kecil Keluar'],
        'menu.bs.pettycash.index.in' => ['Cash Book', 'Master', 'Kas Kecil Masuk'],
        'menu.bs.cashbook.index.out.master' => ['Cash Book', 'Master', 'Kas Keluar'],
        'menu.bs.cashbook.index.in' => ['Cash Book', 'Master', 'Kas Masuk'],
        'menu.bs.cashbook.index.transfer' => ['Cash Book', 'Master', 'Transfer Rekening'],
        'menu.bs.cashbook.giro-calendar' => ['Cash Book', 'Report/Query', 'Kalender Cek Giro'],
        'menu.bs.advance.card' => ['Cash Book', 'Report/Query', 'Kartu Bon Sementara'],
        'menu.bs.cashbook.outstanding-kasbon' => ['Cash Book', 'Report/Query', 'Outstanding Kasbon'],
        'menu.bs.cashbook.index.out.report-query' => ['Cash Book', 'Report/Query', 'Pengeluaran Kas & Bank'],
        'menu.bs.advance.report' => ['Cash Book', 'Report/Query', 'Rekap Bon Sementara'],
        'menu.bs.cashbook.ledger' => ['Cash Book', 'Report/Query', 'Rekening Ledger'],
        'menu.bs.cashbook.balances' => ['Cash Book', 'Report/Query', 'Rekening Saldo'],
        'menu.bs.advance.card-balance' => ['Cash Book', 'Report/Query', 'Saldo Debit Card'],
        'menu.bs.cashbook.opening.index' => ['Cash Book', 'Saldo Awal', 'Saldo Awal Rekening'],
        'menu.bs.cashbook.repair.index' => ['Cash Book', 'Setup', 'Repair Data'],
        'menu.bs.cashbook.signature-barcode.index' => ['Cash Book', 'Setup', 'Set Up Barcode'],
        'menu.bs.cashbook.credit-card.index' => ['Cash Book', 'Setup', 'Set Up Debit Card'],
        'menu.bs.advance.create' => ['Cash Book', 'Transaksi Bon Sementara', 'Pengajuan Bon Sementara'],
        'menu.bs.advance.index' => ['Cash Book', 'Transaksi Bon Sementara', 'Realisasi Bon Sementara'],
        'menu.bs.cheque-out.index.transaksi-cek-giro-keluar' => ['Cash Book', 'Transaksi Cek Giro Keluar', 'Data BG/Cek Keluar'],
        'menu.bs.giro.index.kliring-cek-giro-masuk' => ['Cash Book', 'Transaksi Cek Giro Masuk', 'Kliring Cek Giro Masuk'],
        'menu.bs.giro.create' => ['Cash Book', 'Transaksi Cek Giro Masuk', 'Penerimaan Cek Giro'],
        'menu.bs.giro.index.tolakan-cek-giro-masuk' => ['Cash Book', 'Transaksi Cek Giro Masuk', 'Tolakan Cek Giro Masuk'],
        'menu.bs.pettycash.create.in' => ['Cash Book', 'Transaksi Kas Kecil', 'Penerimaan Kas Kecil'],
        'menu.bs.pettycash.create.out' => ['Cash Book', 'Transaksi Kas Kecil', 'Pengeluaran Kas Kecil'],
        'menu.bs.cashbook.approval-dp' => ['Cash Book', 'Transaksi Penerimaan Bank', 'Approval DP'],
        'menu.bs.cashbook.approval-retensi' => ['Cash Book', 'Transaksi Penerimaan Bank', 'Approval Retensi'],
        'menu.bs.cashbook.approval-um' => ['Cash Book', 'Transaksi Penerimaan Bank', 'Approval UM Penjualan'],
        'menu.bs.cashbook.approval-utjdp' => ['Cash Book', 'Transaksi Penerimaan Bank', 'Approval UTJ'],
        'menu.bs.cashbook.create.in' => ['Cash Book', 'Transaksi Penerimaan Bank', 'Penerimaan Bank'],
        'menu.bs.cashbook.create.transfer' => ['Cash Book', 'Transaksi Penerimaan Bank', 'Transfer Antar Rekening'],
        'menu.bs.cashbook.approvals' => ['Cash Book', 'Transaksi Pengeluaran Bank', 'Approval Pengeluaran Kas & Bank'],
        'menu.bs.cashbook.create.out' => ['Cash Book', 'Transaksi Pengeluaran Bank', 'Registrasi Pengeluaran Kas & Bank'],
        'menu.bs.ap.kuitansi.create' => ['Cash Book', 'Transaksi Pengeluaran Bank', 'Registrasi Pengeluaran Kas & Bank (AP)'],
        'menu.bs.sip.index' => ['Cash Book', 'Transaksi Pengeluaran Bank', 'SIP (Instruksi Bayar)'],

        // ── Contractor Management ──────────────────────────────────────────────────────
        'menu.bs.muk.ctc.index' => ['Contractor Management', 'Master', 'Informasi CTC'],
        'menu.bs.muk.kontrak.index' => ['Contractor Management', 'Master', 'Kontrak SPK (MUK)'],
        'menu.bs.spk.kpl.index' => ['Contractor Management', 'Master', 'Kontrol Progress Lapangan'],
        'menu.bs.spk.retention.index' => ['Contractor Management', 'Master', 'Progress Retensi'],
        'menu.bs.spk.index' => ['Contractor Management', 'Master', 'Surat Perintah Kerja'],
        'menu.bs.muk.tagihan.index' => ['Contractor Management', 'Master', 'Tagihan Progress Project'],
        'menu.bs.spk.query.kartu-hutang' => ['Contractor Management', 'Query', 'Kartu Hutang Supplier - SPK'],
        'menu.bs.spk.query.pemakaian' => ['Contractor Management', 'Query', 'Pemakaian Barang'],
        'menu.bs.spk.query.rekap' => ['Contractor Management', 'Query', 'Rekap SPK'],
        'menu.bs.spk.query.summary' => ['Contractor Management', 'Query', 'Summary SPK'],
        'menu.bs.muk.pasangan.index' => ['Contractor Management', 'Setup', 'Pasangan Unit MUK'],
        'menu.bs.spk.project.index' => ['Contractor Management', 'Setup', 'Project'],
        'menu.bs.spk.draft.edit' => ['Contractor Management', 'Setup', 'Set Up SPK'],
        'menu.bs.spk.addendum.form' => ['Contractor Management', 'Surat Perintah Kerja', 'Addendum SPK (Tambah / Kurang)'],
        'menu.bs.spk.adjust-ppn.form' => ['Contractor Management', 'Surat Perintah Kerja', 'Adjustment PPN'],
        'menu.bs.spk.cancel.form' => ['Contractor Management', 'Surat Perintah Kerja', 'Pembatalan Surat Perintah Kerja'],
        'menu.bs.spk.kpl.create' => ['Contractor Management', 'Surat Perintah Kerja', 'Registrasi Kontrol Progress Lapangan'],
        'menu.bs.spk.retention.create' => ['Contractor Management', 'Surat Perintah Kerja', 'Registrasi Retensi'],
        'menu.bs.spk.create' => ['Contractor Management', 'Surat Perintah Kerja', 'Registrasi Surat Perintah Kerja'],

        // ── General Ledger ─────────────────────────────────────────────────────────────
        'menu.bs.gl.cashflow.direct' => ['General Ledger', 'Cash Flow Direct', 'Cash Flow Direct Multi Periode'],
        'menu.bs.gl.cashflow.indirect' => ['General Ledger', 'Cash Flow Indirect', 'Cash Flow Indirect Multi Periode'],
        'menu.bs.gl.printhub' => ['General Ledger', 'General Function', 'General Function'],
        'menu.bs.gl.journal.approval' => ['General Ledger', 'Generate Lap. Keuangan', 'Approval Jurnal'],
        'menu.bs.gl.journal.finalisasi' => ['General Ledger', 'Generate Lap. Keuangan', 'Finalisasi Jurnal'],
        'menu.bs.gl.late-closing' => ['General Ledger', 'Generate Lap. Keuangan', 'Late Closing'],
        'menu.bs.closing.index' => ['General Ledger', 'Generate Lap. Perusahaan', 'Closing Fiskal'],
        'menu.bs.gl.journal.import' => ['General Ledger', 'Jurnal Memorial', 'Import Jurnal'],
        'menu.bs.gl.journal.create' => ['General Ledger', 'Jurnal Memorial', 'Registrasi Jurnal Memorial'],
        'menu.bs.gl.incomestatement' => ['General Ledger', 'Laba Rugi', 'Laba Rugi'],
        'menu.bs.gl.comparative.laba-rugi' => ['General Ledger', 'Laba Rugi', 'Laba Rugi Komparasi (Bulanan)'],
        'menu.bs.gl.extra.lr-tahunan' => ['General Ledger', 'Laba Rugi', 'Laba Rugi Komparasi (Tahunan)'],
        'menu.bs.gl.extra.lr-multi' => ['General Ledger', 'Laba Rugi', 'Laba Rugi Multi Periode'],
        'menu.bs.gl.extra.lr-banding' => ['General Ledger', 'Laba Rugi', 'Laba Rugi Perbandingan'],
        'menu.bs.gl.lr-budget' => ['General Ledger', 'Laba Rugi', 'Laba Rugi vs Budget'],
        'menu.bs.accounts.index' => ['General Ledger', 'Master', 'Chart of Account'],
        'menu.bs.cost-centers.index' => ['General Ledger', 'Master', 'Cost Center'],
        'menu.bs.journal-dimensions.index' => ['General Ledger', 'Master', 'Dimensi Jurnal'],
        'menu.bs.gl.journal.index' => ['General Ledger', 'Master', 'Jurnal'],
        'menu.bs.gl.cf-mapping.index' => ['General Ledger', 'Master', 'Mapping Cash Flow Direct'],
        'menu.bs.gl.extra.histori' => ['General Ledger', 'Neraca', 'Histori Saldo'],
        'menu.bs.gl.balancesheet' => ['General Ledger', 'Neraca', 'Neraca'],
        'menu.bs.gl.comparative.neraca' => ['General Ledger', 'Neraca', 'Neraca Komparasi (Bulanan)'],
        'menu.bs.gl.extra.tahunan' => ['General Ledger', 'Neraca', 'Neraca Komparasi (Tahunan)'],
        'menu.bs.gl.worksheet' => ['General Ledger', 'Neraca', 'Neraca Lajur'],
        'menu.bs.gl.extra.multi' => ['General Ledger', 'Neraca', 'Neraca Multi Periode'],
        'menu.bs.gl.trialbalance' => ['General Ledger', 'Neraca', 'Neraca Saldo'],
        'menu.bs.gl.journal.detail' => ['General Ledger', 'Query', 'Detail Transaksi Jurnal'],
        'menu.bs.gl.ledger' => ['General Ledger', 'Query', 'General Ledger'],
        'menu.bs.gl.saldo-awal.confirm' => ['General Ledger', 'Saldo Awal', 'Confirm Saldo Awal'],
        'menu.bs.closing.saldoawal' => ['General Ledger', 'Saldo Awal', 'Input Saldo Awal'],
        'menu.bs.gl.cf-category.index.direct' => ['General Ledger', 'Setup', 'Kategori Cash Flow Direct'],
        'menu.bs.gl.cf-category.index.indirect' => ['General Ledger', 'Setup', 'Kategori Cash Flow Indirect'],
        'menu.bs.gl.kurs.index' => ['General Ledger', 'Setup', 'Kurs Sistem'],
        'menu.bs.gl.currency.index' => ['General Ledger', 'Setup', 'Multi Currency'],
        'menu.bs.gl.repair.index' => ['General Ledger', 'Setup', 'Repair Data'],
    ];

    /**
     * Baris menu untuk sebuah kelompok izin.
     *
     * @return array{0:string,1:string,2:string} [Modul, Sub-modul, Nama menu]
     */
    public static function untuk(string $kelompok, string $fallbackLabel): array
    {
        return self::PETA[$kelompok] ?? self::PETA_TAMPIL[$kelompok] ?? [self::MODUL_LAINNYA, '', $fallbackLabel];
    }

    /** Posisi modul pada urutan sidebar; yang tak dikenal selalu di belakang. */
    public static function urutanModul(string $modul): int
    {
        $i = array_search($modul, self::URUTAN_MODUL, true);

        return $i === false ? PHP_INT_MAX : (int) $i;
    }
}
