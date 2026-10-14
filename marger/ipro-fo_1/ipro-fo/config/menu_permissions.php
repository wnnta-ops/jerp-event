<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| IZIN TAMPIL MENU — satu izin per butir sidebar
|------------------------------------------------------------------------------
|
| Dipakai bersama izin AKSI, dan keduanya harus terpenuhi:
|
|   izin aksi    menentukan BOLEH TIDAKNYA layarnya dibuka — dijaga juga di controller
|   izin tampil  menentukan KELIHATAN TIDAKNYA butirnya di sidebar
|
| Gunanya: satu izin aksi kerap membuka beberapa menu sekaligus. `ap.invoice.view` membuka
| Invoice Hutang, Laporan Invoice Hutang, DAN Registrasi Invoice Hutang; `gl.journal.view`
| membuka enam menu. Sebelum ini tidak ada cara memunculkan sebagian saja, sehingga layar
| Grup Akses BO tidak pernah bisa dibuat secermin sidebar yang dilihat pengguna.
|
| MEMATIKAN "TAMPIL" MENYEMBUNYIKAN, BUKAN MENGAMANKAN. Alamatnya tetap bisa diketik selama
| izin aksinya masih dipegang. Untuk benar-benar menutup, yang dicabut izin aksinya.
|
| Isi tabel: kunci izin => [izin aksi yang menyertainya, jalur menunya untuk label].
| Diturunkan dari resources/js/Components/Layout/navGroups.ts — satu-satunya sumber sidebar.
| Menambah menu baru di sana berarti menambah barisnya di sini; yang tidak tercantum akan
| selalu tampil (hasPermission(undefined) bernilai benar), jadi tidak ada menu yang hilang
| diam-diam karena kelupaan.
|
*/

return [

    // ── Dashboard Back Office ── (satu izin per sub-dashboard; tanpa satu pun → "Selamat Datang")
    'menu.dashboard.kpr' => ['dashboard.kpr.view', 'Dashboard Back Office › KPR & Collection'],  // Dashboard Back Office
    'menu.dashboard.cash' => ['dashboard.cash.view', 'Dashboard Back Office › Cash Management'],  // Dashboard Back Office
    'menu.dashboard.accounting' => ['dashboard.accounting.view', 'Dashboard Back Office › Accounting, Tax & Budget'],  // Dashboard Back Office
    'menu.dashboard.project' => ['dashboard.project.view', 'Dashboard Back Office › Project'],  // Dashboard Back Office

    // ── Account Payable ──
    'menu.bs.ap.create' => ['ap.invoice.create', 'Generate Invoice › Registrasi Invoice Hutang'],  // Account Payable
    'menu.bs.ap.index' => ['ap.invoice.view', 'Master › Invoice Hutang'],  // Account Payable
    'menu.bs.ap.kuitansi.index' => ['ap.kuitansi.view', 'Master › Kuitansi Hutang'],  // Account Payable
    'menu.bs.ap.reimburse.index' => ['ap.reimburse.view', 'Generate Invoice › Reimburse Biaya Karyawan'],  // Account Payable
    'menu.bs.ap.deposit.index' => ['ap.deposit.view', 'Generate Invoice › Deposit Renovasi Customer'],  // Account Payable
    'menu.bs.ap.penghapusan.index' => ['ap.penghapusan.view', 'Penyesuaian Invoice › Penghapusan Nilai Hutang'],  // Account Payable
    'menu.bs.ap.posting.spk.index' => ['ap.posting.view', 'Posting Invoice SPK › Posting Invoice SPK'],  // Account Payable
    'menu.bs.ap.barang-siap-jual.index' => ['ap.posting.view', 'Posting Invoice SPK › Posting ke Barang Siap Dijual'],  // Account Payable
    'menu.bs.ap.posting.index' => ['ap.posting.view', 'Posting Invoice dari FO › Posting Invoice dari FO'],  // Account Payable
    'menu.bs.ap.posting.po.index' => ['ap.posting.view', 'Posting Invoice dari Purchase Order › Posting Invoice dari Purchase Order'],  // Account Payable
    'menu.bs.ap.aging.detail' => ['ap.aging.view', 'Query › Aging Hutang'],  // Account Payable
    'menu.bs.ap.aging-supplier' => ['ap.aging.view', 'Query › Aging Hutang Supplier'],  // Account Payable
    'menu.bs.ap.card' => ['ap.paycard.view', 'Query › Kartu Hutang'],  // Account Payable
    'menu.bs.ap.payment-card' => ['ap.paycard.view', 'Query › Kartu Pembayaran Hutang'],  // Account Payable
    'menu.bs.ap.report' => ['ap.invoice.view', 'Query › Laporan Invoice Hutang'],  // Account Payable
    'menu.bs.ap.penghapusan.report' => ['ap.penghapusan.view', 'Query › Laporan Penghapusan Hutang'],  // Account Payable
    'menu.bs.projection.hutang' => ['ap.invoice.view', 'Query › Proyeksi Hutang'],  // Account Payable
    'menu.bs.tax.pph' => ['tax.pph.view', 'Query › Rekap PPh Dipotong'],  // Account Payable
    'menu.bs.ap.opening.index' => ['ap.opening.view', 'Saldo Awal › Saldo Awal'],  // Account Payable
    'menu.bs.gimmick.categories.index' => ['ap.category.view', 'Setup › Kategori Gimmick'],  // Account Payable
    'menu.bs.ap.categories.index' => ['ap.category.view', 'Setup › Kategori Invoice'],  // Account Payable
    'menu.bs.ap.repair.index' => ['ap.repair.view', 'Setup › Repair Data'],  // Account Payable

    // ── Aset Tetap ──
    'menu.bs.fa.create' => ['bs.asset.create', 'Registrasi › Registrasi Aset'],  // Aset Tetap
    'menu.bs.fa.run' => ['bs.asset.run', 'Registrasi › Registrasi Penyusutan'],  // Aset Tetap
    'menu.bs.bulk.form.asset' => ['businesssupport.setup.manage', 'Registrasi › Upload Aset Massal'],  // Aset Tetap
    'menu.bs.fa.penjualan' => ['bs.asset.view', 'Report / Query › Penjualan Aset'],  // Aset Tetap
    'menu.bs.fa.summary' => ['bs.asset.view', 'Report / Query › Summary Aset'],  // Aset Tetap

    // ── Booked Revenue ──
    'menu.bs.tax.keluaran' => ['tax.ppn-keluaran.view', 'Verifikasi Faktur Pajak › PPN Keluaran'],  // Booked Revenue
    'menu.bs.tax.masukan' => ['tax.ppn-masukan.view', 'Verifikasi Faktur Pajak › PPN Masukan'],  // Booked Revenue

    // ── Budget Control ──
    'menu.bs.budget.allocation.create' => ['budget.allocation.create', 'Alokasi Budget › Alokasi Budget'],  // Budget Control
    'menu.bs.budget.allocation.approvals' => ['budget.allocation.approve', 'Alokasi Budget › Approval Alokasi Budget'],  // Budget Control
    'menu.bs.budget.correction.approvals' => ['budget.correction.approve', 'Koreksi Budget › Approval Koreksi Budget'],  // Budget Control
    'menu.bs.budget.correction.create' => ['budget.correction.create', 'Koreksi Budget › Koreksi Budget'],  // Budget Control
    'menu.bs.budget.allocation.index' => ['budget.allocation.view', 'Master › Alokasi Budget Control'],  // Budget Control
    'menu.bs.budget.index' => ['budget.master.view', 'Master › Budget Control'],  // Budget Control
    'menu.bs.budget.correction.index' => ['budget.correction.view', 'Master › Koreksi Budget Control'],  // Budget Control
    'menu.bs.budget.query.allocation' => ['budget.query.allocation', 'Query › Alokasi Budget'],  // Budget Control
    'menu.bs.budget.query.correction' => ['budget.query.correction', 'Query › Koreksi Budget'],  // Budget Control
    'menu.bs.budget.query.master' => ['budget.query.master', 'Query › Master Budget'],  // Budget Control
    'menu.bs.budget.query.realization' => ['budget.query.realization', 'Query › Realisasi Budget'],  // Budget Control
    'menu.bs.budget.query.report' => ['budget.query.realization', 'Query › Report Budget'],  // Budget Control
    'menu.bs.budget.approvals' => ['budget.master.approve', 'Upload Budget › Approval Budget'],  // Budget Control
    'menu.bs.budget.upload.index' => ['budget.upload.view', 'Upload Budget › Upload Budget'],  // Budget Control

    // ── COGS ──
    'menu.bs.cogs2.buka.actual.housing' => ['cogs2.view', 'Actual Costing COGS › Actual Costing COGS-Housing'],  // COGS
    'menu.bs.cogs2.buka.actual.infra' => ['cogs2.view', 'Actual Costing COGS › Actual Costing COGS-Infra'],  // COGS
    'menu.bs.cogs2.buka.actual.license' => ['cogs2.view', 'Actual Costing COGS › Actual Costing COGS-License'],  // COGS
    'menu.bs.cogs2.buka.actual.tanah' => ['cogs2.view', 'Actual Costing COGS › Actual Costing COGS-Tanah'],  // COGS
    'menu.bs.cogs2.index' => ['cogs2.view', 'Perhitungan COGS › Daftar Periode'],  // COGS
    'menu.bs.cogs2.buka.perhitungan' => ['cogs2.view', 'Perhitungan COGS › Perhitungan COGS'],  // COGS
    'menu.bs.cogs2.buka.rincian' => ['cogs2.view', 'Rincian COGS › Rincian COGS'],  // COGS
    'menu.bs.cogs2.buka.kategori.housing' => ['cogs2.view', 'Set Up Kategori COGS › Set Up Kategori COGS-Housing'],  // COGS
    'menu.bs.cogs2.buka.kategori.infra' => ['cogs2.view', 'Set Up Kategori COGS › Set Up Kategori COGS-Infra'],  // COGS
    'menu.bs.cogs2.buka.kategori.license' => ['cogs2.view', 'Set Up Kategori COGS › Set Up Kategori COGS-License'],  // COGS
    'menu.bs.cogs2.buka.kategori.tanah' => ['cogs2.view', 'Set Up Kategori COGS › Set Up Kategori COGS-Tanah'],  // COGS

    // ── Cash Book ──
    'menu.bs.advance.allocation.index' => ['cashbook.advance.view', 'Bon Sementara (Finance) › Alokasi Bon Sementara Finance'],  // Cash Book
    'menu.bs.advance.payments' => ['cashbook.advance.view', 'Bon Sementara (Finance) › Bon Sementara Finance'],  // Cash Book
    'menu.bs.advance.index.1' => ['cashbook.advance.view', 'Bon Sementara (Finance) › Realisasi Bon Sementara Finance'],  // Cash Book
    'menu.bs.advance.cancelled' => ['cashbook.advance.pay', 'Bon Sementara (Finance) › Transaksi Bon Batal (Riwayat)'],  // Cash Book
    'menu.bs.cashflow.report' => ['cashbook.ledger.view', 'Cash Flow › Report Cashflow'],  // Cash Book
    'menu.bs.cashflow.categories' => ['cashbook.ledger.view', 'Cash Flow › Set Up Kategori'],  // Cash Book
    'menu.bs.cashflow.formulas' => ['cashbook.ledger.view', 'Cash Flow › Set Up Rumus'],  // Cash Book
    'menu.bs.cashbook.printhub' => ['cashbook.print.view', 'General Function › General Function'],  // Cash Book
    'menu.bs.cheque-out.index.master' => ['cashbook.cheque-out.view', 'Master › Cek Giro Keluar'],  // Cash Book
    'menu.bs.giro.index.master' => ['cashbook.giro.view', 'Master › Cek Giro Masuk'],  // Cash Book
    'menu.bs.pettycash.index.out' => ['cashbook.pettycash.view', 'Master › Kas Kecil Keluar'],  // Cash Book
    'menu.bs.pettycash.index.in' => ['cashbook.pettycash.view', 'Master › Kas Kecil Masuk'],  // Cash Book
    'menu.bs.cashbook.index.out.master' => ['cashbook.transaction.view', 'Master › Kas Keluar'],  // Cash Book
    'menu.bs.cashbook.index.in' => ['cashbook.transaction.view', 'Master › Kas Masuk'],  // Cash Book
    'menu.bs.cashbook.index.transfer' => ['cashbook.transaction.view', 'Master › Transfer Rekening'],  // Cash Book
    'menu.bs.cashbook.giro-calendar' => ['cashbook.giro-calendar.view', 'Report/Query › Kalender Cek Giro'],  // Cash Book
    'menu.bs.advance.card' => ['cashbook.advance.card', 'Report/Query › Kartu Bon Sementara'],  // Cash Book
    'menu.bs.cashbook.outstanding-kasbon' => ['cashbook.advance.report', 'Report/Query › Outstanding Kasbon'],  // Cash Book
    'menu.bs.cashbook.index.out.report-query' => ['cashbook.transaction.view', 'Report/Query › Pengeluaran Kas & Bank'],  // Cash Book
    'menu.bs.advance.report' => ['cashbook.advance.report', 'Report/Query › Rekap Bon Sementara'],  // Cash Book
    'menu.bs.cashbook.ledger' => ['cashbook.ledger.view', 'Report/Query › Rekening Ledger'],  // Cash Book
    'menu.bs.cashbook.balances' => ['cashbook.saldo.view', 'Report/Query › Rekening Saldo'],  // Cash Book
    'menu.bs.advance.card-balance' => ['cashbook.advance.card', 'Report/Query › Saldo Debit Card'],  // Cash Book
    'menu.bs.cashbook.opening.index' => ['cashbook.opening.view', 'Saldo Awal › Saldo Awal Rekening'],  // Cash Book
    'menu.bs.cashbook.repair.index' => ['cashbook.repair.view', 'Setup › Repair Data'],  // Cash Book
    'menu.bs.cashbook.signature-barcode.index' => ['cashbook.print.view', 'Setup › Set Up Barcode'],  // Cash Book
    'menu.bs.cashbook.credit-card.index' => ['cashbook.saldo.view', 'Setup › Set Up Debit Card'],  // Cash Book
    'menu.bs.advance.create' => ['cashbook.advance.create', 'Transaksi Bon Sementara › Pengajuan Bon Sementara'],  // Cash Book
    'menu.bs.advance.index' => ['cashbook.advance.view', 'Transaksi Bon Sementara › Realisasi Bon Sementara'],  // Cash Book
    'menu.bs.cheque-out.index.transaksi-cek-giro-keluar' => ['cashbook.cheque-out.view', 'Transaksi Cek Giro Keluar › Data BG/Cek Keluar'],  // Cash Book
    'menu.bs.giro.index.kliring-cek-giro-masuk' => ['cashbook.giro.view', 'Transaksi Cek Giro Masuk › Kliring Cek Giro Masuk'],  // Cash Book
    'menu.bs.giro.create' => ['cashbook.giro.create', 'Transaksi Cek Giro Masuk › Penerimaan Cek Giro'],  // Cash Book
    'menu.bs.giro.index.tolakan-cek-giro-masuk' => ['cashbook.giro.view', 'Transaksi Cek Giro Masuk › Tolakan Cek Giro Masuk'],  // Cash Book
    'menu.bs.pettycash.create.in' => ['cashbook.pettycash.create', 'Transaksi Kas Kecil › Penerimaan Kas Kecil'],  // Cash Book
    'menu.bs.pettycash.create.out' => ['cashbook.pettycash.create', 'Transaksi Kas Kecil › Pengeluaran Kas Kecil'],  // Cash Book
    'menu.bs.cashbook.approval-dp' => ['cashbook.approval-utjdp.view', 'Transaksi Penerimaan Bank › Approval DP'],  // Cash Book
    'menu.bs.cashbook.approval-retensi' => ['cashbook.approval-retensi.view', 'Transaksi Penerimaan Bank › Approval Retensi'],  // Cash Book
    'menu.bs.cashbook.approval-um' => ['cashbook.approval-utjdp.view', 'Transaksi Penerimaan Bank › Approval UM Penjualan'],  // Cash Book
    'menu.bs.cashbook.approval-utjdp' => ['cashbook.approval-utjdp.view', 'Transaksi Penerimaan Bank › Approval UTJ'],  // Cash Book
    'menu.bs.cashbook.create.in' => ['cashbook.transaction.create', 'Transaksi Penerimaan Bank › Penerimaan Bank'],  // Cash Book
    'menu.bs.cashbook.create.transfer' => ['cashbook.transaction.create', 'Transaksi Penerimaan Bank › Transfer Antar Rekening'],  // Cash Book
    'menu.bs.cashbook.approvals' => ['cashbook.transaction.approve', 'Transaksi Pengeluaran Bank › Approval Pengeluaran Kas & Bank'],  // Cash Book
    'menu.bs.cashbook.create.out' => ['cashbook.transaction.create', 'Transaksi Pengeluaran Bank › Registrasi Pengeluaran Kas & Bank'],  // Cash Book
    'menu.bs.ap.kuitansi.create' => ['ap.kuitansi.create', 'Transaksi Pengeluaran Bank › Registrasi Pengeluaran Kas & Bank (AP)'],  // Cash Book
    'menu.bs.sip.index' => ['bs.sip.*', 'Transaksi Pengeluaran Bank › SIP (Instruksi Bayar)'],  // Cash Book

    // ── Contractor Management ──
    'menu.bs.muk.ctc.index' => ['ap.posting.*', 'Master › Informasi CTC'],  // Contractor Management
    'menu.bs.muk.kontrak.index' => ['ap.posting.*', 'Master › Kontrak SPK (MUK)'],  // Contractor Management
    'menu.bs.spk.kpl.index' => ['contractor.kpl.view', 'Master › Kontrol Progress Lapangan'],  // Contractor Management
    'menu.bs.spk.retention.index' => ['contractor.retention.view', 'Master › Progress Retensi'],  // Contractor Management
    'menu.bs.spk.index' => ['contractor.spk.view', 'Master › Surat Perintah Kerja'],  // Contractor Management
    'menu.bs.muk.tagihan.index' => ['ap.posting.*', 'Master › Tagihan Progress Project'],  // Contractor Management
    'menu.bs.spk.query.kartu-hutang' => ['contractor.query.view', 'Query › Kartu Hutang Supplier - SPK'],  // Contractor Management
    'menu.bs.spk.query.pemakaian' => ['contractor.query.view', 'Query › Pemakaian Barang'],  // Contractor Management
    'menu.bs.spk.query.rekap' => ['contractor.query.view', 'Query › Rekap SPK'],  // Contractor Management
    'menu.bs.spk.query.summary' => ['contractor.query.view', 'Query › Summary SPK'],  // Contractor Management
    'menu.bs.muk.pasangan.index' => ['contractor.project.view', 'Setup › Pasangan Unit MUK'],  // Contractor Management
    'menu.bs.spk.project.index' => ['contractor.project.view', 'Setup › Project'],  // Contractor Management
    'menu.bs.spk.draft.edit' => ['contractor.spk.update', 'Setup › Set Up SPK'],  // Contractor Management
    'menu.bs.spk.addendum.form' => ['contractor.spk.view', 'Surat Perintah Kerja › Addendum SPK (Tambah / Kurang)'],  // Contractor Management
    'menu.bs.spk.adjust-ppn.form' => ['contractor.spk.view', 'Surat Perintah Kerja › Adjustment PPN'],  // Contractor Management
    'menu.bs.spk.cancel.form' => ['contractor.spk.view', 'Surat Perintah Kerja › Pembatalan Surat Perintah Kerja'],  // Contractor Management
    'menu.bs.spk.kpl.create' => ['contractor.kpl.create', 'Surat Perintah Kerja › Registrasi Kontrol Progress Lapangan'],  // Contractor Management
    'menu.bs.spk.retention.create' => ['contractor.retention.view', 'Surat Perintah Kerja › Registrasi Retensi'],  // Contractor Management
    'menu.bs.spk.create' => ['contractor.spk.create', 'Surat Perintah Kerja › Registrasi Surat Perintah Kerja'],  // Contractor Management

    // ── General Ledger ──
    'menu.bs.gl.cashflow.direct' => ['gl.cashflow.direct.view', 'Cash Flow Direct › Cash Flow Direct Multi Periode'],  // General Ledger
    'menu.bs.gl.cashflow.indirect' => ['gl.cashflow.direct.view', 'Cash Flow Indirect › Cash Flow Indirect Multi Periode'],  // General Ledger
    'menu.bs.gl.printhub' => ['gl.ledger.view', 'General Function › General Function'],  // General Ledger
    'menu.bs.gl.journal.approval' => ['gl.journal.approve', 'Generate Lap. Keuangan › Approval Jurnal'],  // General Ledger
    'menu.bs.gl.journal.finalisasi' => ['gl.journal.finalize', 'Generate Lap. Keuangan › Finalisasi Jurnal'],  // General Ledger
    'menu.bs.gl.late-closing' => ['gl.late-closing.view', 'Generate Lap. Keuangan › Late Closing'],  // General Ledger
    'menu.bs.closing.index' => ['gl.closing.view', 'Generate Lap. Perusahaan › Closing Fiskal'],  // General Ledger
    'menu.bs.gl.journal.import' => ['gl.journal.import', 'Jurnal Memorial › Import Jurnal'],  // General Ledger
    'menu.bs.gl.journal.create' => ['gl.journal.create', 'Jurnal Memorial › Registrasi Jurnal Memorial'],  // General Ledger
    'menu.bs.gl.incomestatement' => ['gl.financials.view', 'Laba Rugi › Laba Rugi'],  // General Ledger
    'menu.bs.gl.comparative.laba-rugi' => ['gl.comparative.view', 'Laba Rugi › Laba Rugi Komparasi (Bulanan)'],  // General Ledger
    'menu.bs.gl.extra.lr-tahunan' => ['gl.extra-report.view', 'Laba Rugi › Laba Rugi Komparasi (Tahunan)'],  // General Ledger
    'menu.bs.gl.extra.lr-multi' => ['gl.extra-report.view', 'Laba Rugi › Laba Rugi Multi Periode'],  // General Ledger
    'menu.bs.gl.extra.lr-banding' => ['gl.extra-report.view', 'Laba Rugi › Laba Rugi Perbandingan'],  // General Ledger
    'menu.bs.gl.lr-budget' => ['gl.financials.view', 'Laba Rugi › Laba Rugi vs Budget'],  // General Ledger
    'menu.bs.accounts.index' => ['gl.account.view', 'Master › Chart of Account'],  // General Ledger
    'menu.bs.cost-centers.index' => ['gl.cost-center.view', 'Master › Cost Center'],  // General Ledger
    'menu.bs.journal-dimensions.index' => ['gl.journal-dimension.view', 'Master › Dimensi Jurnal'],  // General Ledger
    'menu.bs.gl.journal.index' => ['gl.journal.view', 'Master › Jurnal'],  // General Ledger
    'menu.bs.gl.cf-mapping.index' => ['gl.cf-mapping.view', 'Master › Mapping Cash Flow Direct'],  // General Ledger
    'menu.bs.gl.extra.histori' => ['gl.extra-report.view', 'Neraca › Histori Saldo'],  // General Ledger
    'menu.bs.gl.balancesheet' => ['gl.financials.view', 'Neraca › Neraca'],  // General Ledger
    'menu.bs.gl.comparative.neraca' => ['gl.comparative.view', 'Neraca › Neraca Komparasi (Bulanan)'],  // General Ledger
    'menu.bs.gl.extra.tahunan' => ['gl.extra-report.view', 'Neraca › Neraca Komparasi (Tahunan)'],  // General Ledger
    'menu.bs.gl.worksheet' => ['gl.worksheet.view', 'Neraca › Neraca Lajur'],  // General Ledger
    'menu.bs.gl.extra.multi' => ['gl.extra-report.view', 'Neraca › Neraca Multi Periode'],  // General Ledger
    'menu.bs.gl.trialbalance' => ['gl.trialbalance.view', 'Neraca › Neraca Saldo'],  // General Ledger
    'menu.bs.gl.journal.detail' => ['gl.journal.view', 'Query › Detail Transaksi Jurnal'],  // General Ledger
    'menu.bs.gl.ledger' => ['gl.ledger.view', 'Query › General Ledger'],  // General Ledger
    'menu.bs.gl.saldo-awal.confirm' => ['gl.saldo-awal.confirm', 'Saldo Awal › Confirm Saldo Awal'],  // General Ledger
    'menu.bs.closing.saldoawal' => ['gl.saldo-awal.view', 'Saldo Awal › Input Saldo Awal'],  // General Ledger
    'menu.bs.gl.cf-category.index.direct' => ['gl.cf-category.view', 'Setup › Kategori Cash Flow Direct'],  // General Ledger
    'menu.bs.gl.cf-category.index.indirect' => ['gl.cf-category.view', 'Setup › Kategori Cash Flow Indirect'],  // General Ledger
    'menu.bs.gl.kurs.index' => ['gl.kurs.view', 'Setup › Kurs Sistem'],  // General Ledger
    'menu.bs.gl.currency.index' => ['gl.currency.view', 'Setup › Multi Currency'],  // General Ledger
    'menu.bs.gl.repair.index' => ['gl.repair.view', 'Setup › Repair Data'],  // General Ledger
];
