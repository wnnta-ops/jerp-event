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
        'ap.reimburse' => ['Account Payable', 'Generate Invoice', 'Reimburse Biaya Karyawan'],
        'ap.deposit' => ['Account Payable', 'Generate Invoice', 'Deposit Renovasi Customer'],
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
     * Izin TAMPIL (`menu.*`) => [Modul, Sub-modul, Nama menu, izin AKSI-nya] — satu butir sidebar BO per baris.
     *
     * Izin aksi (kolom ke-4) adalah izin yang harus dipegang supaya layarnya bisa DIBUKA; menu baru
     * muncul bila Tampil DAN izin itu terpenuhi. Matriks menaruhnya sebagai centang "Buka layar" di
     * baris menu yang sama. Pola `x.*` = izin apa pun berawalan x. (cocokIzin di aplikasi BO).
     *
     * Izin ini tidak membuka layar apa pun; ia hanya menentukan butir sidebar mana yang muncul
     * (izin aksinya tetap harus dipegang). Di matriks, centangnya menumpang di baris menu yang
     * namanya sama, sehingga "Invoice Hutang" berisi Tampil + Lihat/Buat/… dalam satu baris.
     *
     * Disalin dari config/menu_permissions.php milik BO — aplikasi ini tidak bisa membaca config
     * aplikasi lain. Menambah butir di sana berarti menambah barisnya di sini; yang terlupa
     * tidak hilang, ia jatuh ke seksi "Belum dipetakan".
     *
     * @var array<string,array{0:string,1:string,2:string,3:string}>
     */
    public const PETA_TAMPIL = [
        // ── Account Payable ────────────────────────────────────────────────────────────
        'menu.bs.ap.create' => ['Account Payable', 'Generate Invoice', 'Registrasi Invoice Hutang', 'ap.invoice.create'],
        'menu.bs.ap.index' => ['Account Payable', 'Master', 'Invoice Hutang', 'ap.invoice.view'],
        'menu.bs.ap.kuitansi.index' => ['Account Payable', 'Master', 'Kuitansi Hutang', 'ap.kuitansi.view'],
        'menu.bs.ap.reimburse.index' => ['Account Payable', 'Generate Invoice', 'Reimburse Biaya Karyawan', 'ap.reimburse.view'],
        'menu.bs.ap.deposit.index' => ['Account Payable', 'Generate Invoice', 'Deposit Renovasi Customer', 'ap.deposit.view'],
        'menu.bs.ap.penghapusan.index' => ['Account Payable', 'Penyesuaian Invoice', 'Penghapusan Nilai Hutang', 'ap.penghapusan.view'],
        'menu.bs.ap.posting.spk.index' => ['Account Payable', 'Posting Invoice SPK', 'Posting Invoice SPK', 'ap.posting.view'],
        'menu.bs.ap.barang-siap-jual.index' => ['Account Payable', 'Posting Invoice SPK', 'Posting ke Barang Siap Dijual', 'ap.posting.view'],
        'menu.bs.ap.posting.index' => ['Account Payable', 'Posting Invoice dari FO', 'Posting Invoice dari FO', 'ap.posting.view'],
        'menu.bs.ap.posting.po.index' => ['Account Payable', 'Posting Invoice dari Purchase Order', 'Posting Invoice dari Purchase Order', 'ap.posting.view'],
        'menu.bs.ap.aging.detail' => ['Account Payable', 'Query', 'Aging Hutang', 'ap.aging.view'],
        'menu.bs.ap.aging-supplier' => ['Account Payable', 'Query', 'Aging Hutang Supplier', 'ap.aging.view'],
        'menu.bs.ap.card' => ['Account Payable', 'Query', 'Kartu Hutang', 'ap.paycard.view'],
        'menu.bs.ap.payment-card' => ['Account Payable', 'Query', 'Kartu Pembayaran Hutang', 'ap.paycard.view'],
        'menu.bs.ap.report' => ['Account Payable', 'Query', 'Laporan Invoice Hutang', 'ap.invoice.view'],
        'menu.bs.ap.penghapusan.report' => ['Account Payable', 'Query', 'Laporan Penghapusan Hutang', 'ap.penghapusan.view'],
        'menu.bs.projection.hutang' => ['Account Payable', 'Query', 'Proyeksi Hutang', 'ap.invoice.view'],
        'menu.bs.tax.pph' => ['Account Payable', 'Query', 'Rekap PPh Dipotong', 'tax.pph.view'],
        'menu.bs.ap.opening.index' => ['Account Payable', 'Saldo Awal', 'Saldo Awal', 'ap.opening.view'],
        'menu.bs.gimmick.categories.index' => ['Account Payable', 'Setup', 'Kategori Gimmick', 'ap.category.view'],
        'menu.bs.ap.categories.index' => ['Account Payable', 'Setup', 'Kategori Invoice', 'ap.category.view'],
        'menu.bs.ap.repair.index' => ['Account Payable', 'Setup', 'Repair Data', 'ap.repair.view'],

        // ── Aset Tetap ─────────────────────────────────────────────────────────────────
        'menu.bs.fa.create' => ['Aset Tetap', 'Registrasi', 'Registrasi Aset', 'bs.asset.create'],
        'menu.bs.fa.run' => ['Aset Tetap', 'Registrasi', 'Registrasi Penyusutan', 'bs.asset.run'],
        'menu.bs.bulk.form.asset' => ['Aset Tetap', 'Registrasi', 'Upload Aset Massal', 'businesssupport.setup.manage'],
        'menu.bs.fa.penjualan' => ['Aset Tetap', 'Report / Query', 'Penjualan Aset', 'bs.asset.view'],
        'menu.bs.fa.summary' => ['Aset Tetap', 'Report / Query', 'Summary Aset', 'bs.asset.view'],

        // ── Booked Revenue ─────────────────────────────────────────────────────────────
        'menu.bs.tax.keluaran' => ['Booked Revenue', 'Verifikasi Faktur Pajak', 'PPN Keluaran', 'tax.ppn-keluaran.view'],
        'menu.bs.tax.masukan' => ['Booked Revenue', 'Verifikasi Faktur Pajak', 'PPN Masukan', 'tax.ppn-masukan.view'],

        // ── Budget Control ─────────────────────────────────────────────────────────────
        'menu.bs.budget.allocation.create' => ['Budget Control', 'Alokasi Budget', 'Alokasi Budget', 'budget.allocation.create'],
        'menu.bs.budget.allocation.approvals' => ['Budget Control', 'Alokasi Budget', 'Approval Alokasi Budget', 'budget.allocation.approve'],
        'menu.bs.budget.correction.approvals' => ['Budget Control', 'Koreksi Budget', 'Approval Koreksi Budget', 'budget.correction.approve'],
        'menu.bs.budget.correction.create' => ['Budget Control', 'Koreksi Budget', 'Koreksi Budget', 'budget.correction.create'],
        'menu.bs.budget.allocation.index' => ['Budget Control', 'Master', 'Alokasi Budget Control', 'budget.allocation.view'],
        'menu.bs.budget.index' => ['Budget Control', 'Master', 'Budget Control', 'budget.master.view'],
        'menu.bs.budget.correction.index' => ['Budget Control', 'Master', 'Koreksi Budget Control', 'budget.correction.view'],
        'menu.bs.budget.query.allocation' => ['Budget Control', 'Query', 'Alokasi Budget', 'budget.query.allocation'],
        'menu.bs.budget.query.correction' => ['Budget Control', 'Query', 'Koreksi Budget', 'budget.query.correction'],
        'menu.bs.budget.query.master' => ['Budget Control', 'Query', 'Master Budget', 'budget.query.master'],
        'menu.bs.budget.query.realization' => ['Budget Control', 'Query', 'Realisasi Budget', 'budget.query.realization'],
        'menu.bs.budget.query.report' => ['Budget Control', 'Query', 'Report Budget', 'budget.query.realization'],
        'menu.bs.budget.approvals' => ['Budget Control', 'Upload Budget', 'Approval Budget', 'budget.master.approve'],
        'menu.bs.budget.upload.index' => ['Budget Control', 'Upload Budget', 'Upload Budget', 'budget.upload.view'],

        // ── COGS ───────────────────────────────────────────────────────────────────────
        'menu.bs.cogs2.buka.actual.housing' => ['COGS', 'Actual Costing COGS', 'Actual Costing COGS-Housing', 'cogs2.view'],
        'menu.bs.cogs2.buka.actual.infra' => ['COGS', 'Actual Costing COGS', 'Actual Costing COGS-Infra', 'cogs2.view'],
        'menu.bs.cogs2.buka.actual.license' => ['COGS', 'Actual Costing COGS', 'Actual Costing COGS-License', 'cogs2.view'],
        'menu.bs.cogs2.buka.actual.tanah' => ['COGS', 'Actual Costing COGS', 'Actual Costing COGS-Tanah', 'cogs2.view'],
        'menu.bs.cogs2.index' => ['COGS', 'Perhitungan COGS', 'Daftar Periode', 'cogs2.view'],
        'menu.bs.cogs2.buka.perhitungan' => ['COGS', 'Perhitungan COGS', 'Perhitungan COGS', 'cogs2.view'],
        'menu.bs.cogs2.buka.rincian' => ['COGS', 'Rincian COGS', 'Rincian COGS', 'cogs2.view'],
        'menu.bs.cogs2.buka.kategori.housing' => ['COGS', 'Set Up Kategori COGS', 'Set Up Kategori COGS-Housing', 'cogs2.view'],
        'menu.bs.cogs2.buka.kategori.infra' => ['COGS', 'Set Up Kategori COGS', 'Set Up Kategori COGS-Infra', 'cogs2.view'],
        'menu.bs.cogs2.buka.kategori.license' => ['COGS', 'Set Up Kategori COGS', 'Set Up Kategori COGS-License', 'cogs2.view'],
        'menu.bs.cogs2.buka.kategori.tanah' => ['COGS', 'Set Up Kategori COGS', 'Set Up Kategori COGS-Tanah', 'cogs2.view'],

        // ── Cash Book ──────────────────────────────────────────────────────────────────
        'menu.bs.advance.allocation.index' => ['Cash Book', 'Bon Sementara (Finance)', 'Alokasi Bon Sementara Finance', 'cashbook.advance.view'],
        'menu.bs.advance.payments' => ['Cash Book', 'Bon Sementara (Finance)', 'Bon Sementara Finance', 'cashbook.advance.view'],
        'menu.bs.advance.index.1' => ['Cash Book', 'Bon Sementara (Finance)', 'Realisasi Bon Sementara Finance', 'cashbook.advance.view'],
        'menu.bs.advance.cancelled' => ['Cash Book', 'Bon Sementara (Finance)', 'Transaksi Bon Batal (Riwayat)', 'cashbook.advance.pay'],
        'menu.bs.cashflow.report' => ['Cash Book', 'Cash Flow', 'Report Cashflow', 'cashbook.ledger.view'],
        'menu.bs.cashflow.categories' => ['Cash Book', 'Cash Flow', 'Set Up Kategori', 'cashbook.ledger.view'],
        'menu.bs.cashflow.formulas' => ['Cash Book', 'Cash Flow', 'Set Up Rumus', 'cashbook.ledger.view'],
        'menu.bs.cashbook.printhub' => ['Cash Book', 'General Function', 'General Function', 'cashbook.print.view'],
        'menu.bs.cheque-out.index.master' => ['Cash Book', 'Master', 'Cek Giro Keluar', 'cashbook.cheque-out.view'],
        'menu.bs.giro.index.master' => ['Cash Book', 'Master', 'Cek Giro Masuk', 'cashbook.giro.view'],
        'menu.bs.pettycash.index.out' => ['Cash Book', 'Master', 'Kas Kecil Keluar', 'cashbook.pettycash.view'],
        'menu.bs.pettycash.index.in' => ['Cash Book', 'Master', 'Kas Kecil Masuk', 'cashbook.pettycash.view'],
        'menu.bs.cashbook.index.out.master' => ['Cash Book', 'Master', 'Kas Keluar', 'cashbook.transaction.view'],
        'menu.bs.cashbook.index.in' => ['Cash Book', 'Master', 'Kas Masuk', 'cashbook.transaction.view'],
        'menu.bs.cashbook.index.transfer' => ['Cash Book', 'Master', 'Transfer Rekening', 'cashbook.transaction.view'],
        'menu.bs.cashbook.giro-calendar' => ['Cash Book', 'Report/Query', 'Kalender Cek Giro', 'cashbook.giro-calendar.view'],
        'menu.bs.advance.card' => ['Cash Book', 'Report/Query', 'Kartu Bon Sementara', 'cashbook.advance.card'],
        'menu.bs.cashbook.outstanding-kasbon' => ['Cash Book', 'Report/Query', 'Outstanding Kasbon', 'cashbook.advance.report'],
        'menu.bs.cashbook.index.out.report-query' => ['Cash Book', 'Report/Query', 'Pengeluaran Kas & Bank', 'cashbook.transaction.view'],
        'menu.bs.advance.report' => ['Cash Book', 'Report/Query', 'Rekap Bon Sementara', 'cashbook.advance.report'],
        'menu.bs.cashbook.ledger' => ['Cash Book', 'Report/Query', 'Rekening Ledger', 'cashbook.ledger.view'],
        'menu.bs.cashbook.balances' => ['Cash Book', 'Report/Query', 'Rekening Saldo', 'cashbook.saldo.view'],
        'menu.bs.advance.card-balance' => ['Cash Book', 'Report/Query', 'Saldo Debit Card', 'cashbook.advance.card'],
        'menu.bs.cashbook.opening.index' => ['Cash Book', 'Saldo Awal', 'Saldo Awal Rekening', 'cashbook.opening.view'],
        'menu.bs.cashbook.repair.index' => ['Cash Book', 'Setup', 'Repair Data', 'cashbook.repair.view'],
        'menu.bs.cashbook.signature-barcode.index' => ['Cash Book', 'Setup', 'Set Up Barcode', 'cashbook.print.view'],
        'menu.bs.cashbook.credit-card.index' => ['Cash Book', 'Setup', 'Set Up Debit Card', 'cashbook.saldo.view'],
        'menu.bs.advance.create' => ['Cash Book', 'Transaksi Bon Sementara', 'Pengajuan Bon Sementara', 'cashbook.advance.create'],
        'menu.bs.advance.index' => ['Cash Book', 'Transaksi Bon Sementara', 'Realisasi Bon Sementara', 'cashbook.advance.view'],
        'menu.bs.cheque-out.index.transaksi-cek-giro-keluar' => ['Cash Book', 'Transaksi Cek Giro Keluar', 'Data BG/Cek Keluar', 'cashbook.cheque-out.view'],
        'menu.bs.giro.index.kliring-cek-giro-masuk' => ['Cash Book', 'Transaksi Cek Giro Masuk', 'Kliring Cek Giro Masuk', 'cashbook.giro.view'],
        'menu.bs.giro.create' => ['Cash Book', 'Transaksi Cek Giro Masuk', 'Penerimaan Cek Giro', 'cashbook.giro.create'],
        'menu.bs.giro.index.tolakan-cek-giro-masuk' => ['Cash Book', 'Transaksi Cek Giro Masuk', 'Tolakan Cek Giro Masuk', 'cashbook.giro.view'],
        'menu.bs.pettycash.create.in' => ['Cash Book', 'Transaksi Kas Kecil', 'Penerimaan Kas Kecil', 'cashbook.pettycash.create'],
        'menu.bs.pettycash.create.out' => ['Cash Book', 'Transaksi Kas Kecil', 'Pengeluaran Kas Kecil', 'cashbook.pettycash.create'],
        'menu.bs.cashbook.approval-dp' => ['Cash Book', 'Transaksi Penerimaan Bank', 'Approval DP', 'cashbook.approval-utjdp.view'],
        'menu.bs.cashbook.approval-retensi' => ['Cash Book', 'Transaksi Penerimaan Bank', 'Approval Retensi', 'cashbook.approval-retensi.view'],
        'menu.bs.cashbook.approval-um' => ['Cash Book', 'Transaksi Penerimaan Bank', 'Approval UM Penjualan', 'cashbook.approval-utjdp.view'],
        'menu.bs.cashbook.approval-utjdp' => ['Cash Book', 'Transaksi Penerimaan Bank', 'Approval UTJ', 'cashbook.approval-utjdp.view'],
        'menu.bs.cashbook.create.in' => ['Cash Book', 'Transaksi Penerimaan Bank', 'Penerimaan Bank', 'cashbook.transaction.create'],
        'menu.bs.cashbook.create.transfer' => ['Cash Book', 'Transaksi Penerimaan Bank', 'Transfer Antar Rekening', 'cashbook.transaction.create'],
        'menu.bs.cashbook.approvals' => ['Cash Book', 'Transaksi Pengeluaran Bank', 'Approval Pengeluaran Kas & Bank', 'cashbook.transaction.approve'],
        'menu.bs.cashbook.create.out' => ['Cash Book', 'Transaksi Pengeluaran Bank', 'Registrasi Pengeluaran Kas & Bank', 'cashbook.transaction.create'],
        'menu.bs.ap.kuitansi.create' => ['Cash Book', 'Transaksi Pengeluaran Bank', 'Registrasi Pengeluaran Kas & Bank (AP)', 'ap.kuitansi.create'],
        'menu.bs.sip.index' => ['Cash Book', 'Transaksi Pengeluaran Bank', 'SIP (Instruksi Bayar)', 'bs.sip.*'],

        // ── Contractor Management ──────────────────────────────────────────────────────
        'menu.bs.muk.ctc.index' => ['Contractor Management', 'Master', 'Informasi CTC', 'ap.posting.*'],
        'menu.bs.muk.kontrak.index' => ['Contractor Management', 'Master', 'Kontrak SPK (MUK)', 'ap.posting.*'],
        'menu.bs.spk.kpl.index' => ['Contractor Management', 'Master', 'Kontrol Progress Lapangan', 'contractor.kpl.view'],
        'menu.bs.spk.retention.index' => ['Contractor Management', 'Master', 'Progress Retensi', 'contractor.retention.view'],
        'menu.bs.spk.index' => ['Contractor Management', 'Master', 'Surat Perintah Kerja', 'contractor.spk.view'],
        'menu.bs.muk.tagihan.index' => ['Contractor Management', 'Master', 'Tagihan Progress Project', 'ap.posting.*'],
        'menu.bs.spk.query.kartu-hutang' => ['Contractor Management', 'Query', 'Kartu Hutang Supplier - SPK', 'contractor.query.view'],
        'menu.bs.spk.query.pemakaian' => ['Contractor Management', 'Query', 'Pemakaian Barang', 'contractor.query.view'],
        'menu.bs.spk.query.rekap' => ['Contractor Management', 'Query', 'Rekap SPK', 'contractor.query.view'],
        'menu.bs.spk.query.summary' => ['Contractor Management', 'Query', 'Summary SPK', 'contractor.query.view'],
        'menu.bs.muk.pasangan.index' => ['Contractor Management', 'Setup', 'Pasangan Unit MUK', 'contractor.project.view'],
        'menu.bs.spk.project.index' => ['Contractor Management', 'Setup', 'Project', 'contractor.project.view'],
        'menu.bs.spk.draft.edit' => ['Contractor Management', 'Setup', 'Set Up SPK', 'contractor.spk.update'],
        'menu.bs.spk.addendum.form' => ['Contractor Management', 'Surat Perintah Kerja', 'Addendum SPK (Tambah / Kurang)', 'contractor.spk.view'],
        'menu.bs.spk.adjust-ppn.form' => ['Contractor Management', 'Surat Perintah Kerja', 'Adjustment PPN', 'contractor.spk.view'],
        'menu.bs.spk.cancel.form' => ['Contractor Management', 'Surat Perintah Kerja', 'Pembatalan Surat Perintah Kerja', 'contractor.spk.view'],
        'menu.bs.spk.kpl.create' => ['Contractor Management', 'Surat Perintah Kerja', 'Registrasi Kontrol Progress Lapangan', 'contractor.kpl.create'],
        'menu.bs.spk.retention.create' => ['Contractor Management', 'Surat Perintah Kerja', 'Registrasi Retensi', 'contractor.retention.view'],
        'menu.bs.spk.create' => ['Contractor Management', 'Surat Perintah Kerja', 'Registrasi Surat Perintah Kerja', 'contractor.spk.create'],

        // ── General Ledger ─────────────────────────────────────────────────────────────
        'menu.bs.gl.cashflow.direct' => ['General Ledger', 'Cash Flow Direct', 'Cash Flow Direct Multi Periode', 'gl.cashflow.direct.view'],
        'menu.bs.gl.cashflow.indirect' => ['General Ledger', 'Cash Flow Indirect', 'Cash Flow Indirect Multi Periode', 'gl.cashflow.direct.view'],
        'menu.bs.gl.printhub' => ['General Ledger', 'General Function', 'General Function', 'gl.ledger.view'],
        'menu.bs.gl.journal.approval' => ['General Ledger', 'Generate Lap. Keuangan', 'Approval Jurnal', 'gl.journal.approve'],
        'menu.bs.gl.journal.finalisasi' => ['General Ledger', 'Generate Lap. Keuangan', 'Finalisasi Jurnal', 'gl.journal.finalize'],
        'menu.bs.gl.late-closing' => ['General Ledger', 'Generate Lap. Keuangan', 'Late Closing', 'gl.late-closing.view'],
        'menu.bs.closing.index' => ['General Ledger', 'Generate Lap. Perusahaan', 'Closing Fiskal', 'gl.closing.view'],
        'menu.bs.gl.journal.import' => ['General Ledger', 'Jurnal Memorial', 'Import Jurnal', 'gl.journal.import'],
        'menu.bs.gl.journal.create' => ['General Ledger', 'Jurnal Memorial', 'Registrasi Jurnal Memorial', 'gl.journal.create'],
        'menu.bs.gl.incomestatement' => ['General Ledger', 'Laba Rugi', 'Laba Rugi', 'gl.financials.view'],
        'menu.bs.gl.comparative.laba-rugi' => ['General Ledger', 'Laba Rugi', 'Laba Rugi Komparasi (Bulanan)', 'gl.comparative.view'],
        'menu.bs.gl.extra.lr-tahunan' => ['General Ledger', 'Laba Rugi', 'Laba Rugi Komparasi (Tahunan)', 'gl.extra-report.view'],
        'menu.bs.gl.extra.lr-multi' => ['General Ledger', 'Laba Rugi', 'Laba Rugi Multi Periode', 'gl.extra-report.view'],
        'menu.bs.gl.extra.lr-banding' => ['General Ledger', 'Laba Rugi', 'Laba Rugi Perbandingan', 'gl.extra-report.view'],
        'menu.bs.gl.lr-budget' => ['General Ledger', 'Laba Rugi', 'Laba Rugi vs Budget', 'gl.financials.view'],
        'menu.bs.accounts.index' => ['General Ledger', 'Master', 'Chart of Account', 'gl.account.view'],
        'menu.bs.cost-centers.index' => ['General Ledger', 'Master', 'Cost Center', 'gl.cost-center.view'],
        'menu.bs.journal-dimensions.index' => ['General Ledger', 'Master', 'Dimensi Jurnal', 'gl.journal-dimension.view'],
        'menu.bs.gl.journal.index' => ['General Ledger', 'Master', 'Jurnal', 'gl.journal.view'],
        'menu.bs.gl.cf-mapping.index' => ['General Ledger', 'Master', 'Mapping Cash Flow Direct', 'gl.cf-mapping.view'],
        'menu.bs.gl.extra.histori' => ['General Ledger', 'Neraca', 'Histori Saldo', 'gl.extra-report.view'],
        'menu.bs.gl.balancesheet' => ['General Ledger', 'Neraca', 'Neraca', 'gl.financials.view'],
        'menu.bs.gl.comparative.neraca' => ['General Ledger', 'Neraca', 'Neraca Komparasi (Bulanan)', 'gl.comparative.view'],
        'menu.bs.gl.extra.tahunan' => ['General Ledger', 'Neraca', 'Neraca Komparasi (Tahunan)', 'gl.extra-report.view'],
        'menu.bs.gl.worksheet' => ['General Ledger', 'Neraca', 'Neraca Lajur', 'gl.worksheet.view'],
        'menu.bs.gl.extra.multi' => ['General Ledger', 'Neraca', 'Neraca Multi Periode', 'gl.extra-report.view'],
        'menu.bs.gl.trialbalance' => ['General Ledger', 'Neraca', 'Neraca Saldo', 'gl.trialbalance.view'],
        'menu.bs.gl.journal.detail' => ['General Ledger', 'Query', 'Detail Transaksi Jurnal', 'gl.journal.view'],
        'menu.bs.gl.ledger' => ['General Ledger', 'Query', 'General Ledger', 'gl.ledger.view'],
        'menu.bs.gl.saldo-awal.confirm' => ['General Ledger', 'Saldo Awal', 'Confirm Saldo Awal', 'gl.saldo-awal.confirm'],
        'menu.bs.closing.saldoawal' => ['General Ledger', 'Saldo Awal', 'Input Saldo Awal', 'gl.saldo-awal.view'],
        'menu.bs.gl.cf-category.index.direct' => ['General Ledger', 'Setup', 'Kategori Cash Flow Direct', 'gl.cf-category.view'],
        'menu.bs.gl.cf-category.index.indirect' => ['General Ledger', 'Setup', 'Kategori Cash Flow Indirect', 'gl.cf-category.view'],
        'menu.bs.gl.kurs.index' => ['General Ledger', 'Setup', 'Kurs Sistem', 'gl.kurs.view'],
        'menu.bs.gl.currency.index' => ['General Ledger', 'Setup', 'Multi Currency', 'gl.currency.view'],
        'menu.bs.gl.repair.index' => ['General Ledger', 'Setup', 'Repair Data', 'gl.repair.view'],
    ];

    /**
     * Baris menu untuk sebuah kelompok izin.
     *
     * @return array{0:string,1:string,2:string} [Modul, Sub-modul, Nama menu]
     */
    public static function untuk(string $kelompok, string $fallbackLabel): array
    {
        if (isset(self::PETA_TAMPIL[$kelompok])) {
            return array_slice(self::PETA_TAMPIL[$kelompok], 0, 3);
        }

        return self::PETA[$kelompok] ?? [self::MODUL_LAINNYA, '', $fallbackLabel];
    }

    /**
     * Izin AKSI yang membuat sebuah butir menu bisa dibuka — pola `x.*` bila menunya menerima
     * izin apa pun berawalan x. null bila bukan izin tampil yang dikenal.
     */
    public static function izinAksiMenu(string $izinTampil): ?string
    {
        return self::PETA_TAMPIL[$izinTampil][3] ?? null;
    }

    /** Posisi modul pada urutan sidebar; yang tak dikenal selalu di belakang. */
    public static function urutanModul(string $modul): int
    {
        $i = array_search($modul, self::URUTAN_MODUL, true);

        return $i === false ? PHP_INT_MAX : (int) $i;
    }
}
