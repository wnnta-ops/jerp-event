<?php

declare(strict_types=1);

/**
 * Pemetaan akun GL default untuk jurnal otomatis transaksi Business Support.
 * Kode mengacu ke Chart of Account riil eProperty (bs_accounts, 6 level).
 * Ubah di sini bila CoA berbeda.
 */
return [
    // Identitas perusahaan untuk dokumen pajak (SPT / e-Faktur). Ubah via .env bila perlu.
    'company' => [
        'name'    => env('COMPANY_NAME', 'PT. MITRA USAHA PROPERTINDO'),
        'npwp'    => env('COMPANY_NPWP', '00.000.000.0-000.000'),
        'address' => env('COMPANY_ADDRESS', 'Sidoarjo, Jawa Timur'),
        // Penandatangan & kota untuk dokumen BAP (Berita Acara Pembayaran) pihak Pemberi Tugas.
        'city'         => env('COMPANY_CITY', 'Sidoarjo'),
        'signer_name'  => env('COMPANY_SIGNER_NAME', 'Rachmat Suhendra'),
        'signer_title' => env('COMPANY_SIGNER_TITLE', 'Direktur'),
    ],

    'gl_accounts' => [
        'inventory'  => '10104010101', // Persediaan — Bangunan Siap Jual
        'payable'    => '20101010101', // Utang Usaha Supplier
        'receivable' => '10102010101', // Piutang Usaha
        'income'     => '40101010101', // Penjualan Usaha
        'unearned_revenue' => '20106060601', // Uang Muka Penjualan (Pendapatan Diterima Dimuka) — Booked Revenue
        'expense'    => '50101010101', // Beban Pokok Penjualan
        'ppn_in'     => '10105010101', // PPN Masukan
        'ppn_out'    => '20104040406', // PPN Keluaran
        'cash'       => '10101020204', // Bank — BCA REK. 6155428914
        'cogs'       => '50101010101', // Beban Pokok Penjualan (HPP)
        'giro_in'    => '10102010102', // Piutang Giro (cek/giro masuk)
        // #153 BG/Cek KELUAR (outgoing cheque). outgoing_cheque_liability = penampung "BG/Cek Beredar"
        // (warkat sudah diserahkan ke vendor tapi belum dicairkan). Default KOSONG: jurnal otomatis
        // hanya terbentuk bila key ini diisi kode akun riil (mis. 20107070710 UTANG JK. PENDEK LAIN-LAIN,
        // atau buat akun khusus "Hutang BG/Cek Beredar"). Selama kosong, siklus tetap jalan tanpa jurnal.
        'outgoing_cheque_liability' => '20101010103', // UTANG BG/CEK BEREDAR (dibuat via migrasi 2026_06_24_000020) → jurnal BG/Cek keluar AKTIF
        // outgoing_cheque_bank = bank default saat warkat dicairkan. Kosong → fallback ke rekening bank
        // penerbit pada warkat (bank_account_id), sehingga umumnya tak perlu diisi.
        'outgoing_cheque_bank'      => '', // kosong → fallback ke rekening bank penerbit warkat
        // Debit default saat warkat "tidak berhubungan hutang" (pihak ketiga) diserahkan — jurnal:
        // Dr Beban Pokok Penjualan / Cr UTANG BG/CEK BEREDAR. Bisa diganti per-transaksi di struktur jurnal.
        'outgoing_cheque_expense'   => '50101010101', // BEBAN POKOK PENJUALAN (default debit pihak ketiga)
        // Debit "Biaya PPh" untuk PPh DITANGGUNG (borne) — jurnal: Dr Biaya PPh / Cr Hutang PPh.
        // Default akun beban umum; bisa diganti per-transaksi di struktur jurnal (semua COA aktif tersedia).
        'pph_borne_expense'         => '50302020228', // BIAYA UMUM & ADMINISTRASI LAIN-LAIN
        'advance_op' => '10107030303', // Uang Muka Operasional (Bon Sementara)
        'retained_earnings' => '80101010101', // LABA RUGI DITAHAN (Kategori 8 — tutup buku tahunan)
        'current_earnings'  => '80101010102', // LABA RUGI TAHUN BERJALAN (Kategori 8)
    ],

    /**
     * Aset Tetap: pemetaan kategori → akun (aset, akumulasi penyusutan, beban penyusutan).
     * Tanah tidak disusutkan (accum/expense null).
     */
    'fixed_asset' => [
        'bangunan'   => ['asset' => '10201010102', 'accum' => '10203030301', 'expense' => '50302020225'],
        'kendaraan'  => ['asset' => '10201010103', 'accum' => '10203030302', 'expense' => '50302020226'],
        'inventaris' => ['asset' => '10201010104', 'accum' => '10203030303', 'expense' => '50302020227'],
        'tanah'      => ['asset' => '10201010101', 'accum' => null, 'expense' => null],
    ],

    /** Pelepasan aset (penghapusan / penjualan) & saldo awal akumulasi penyusutan. */
    'fixed_asset_disposal' => [
        'gain'           => '40201010102', // LABA PENJUALAN ASET TETAP (income)
        'loss'           => '50302020230', // RUGI PENJUALAN/PENGHAPUSAN ASET TETAP (expense)
        'opening_offset' => '80101010101', // LABA RUGI DITAHAN (lawan saldo awal akumulasi penyusutan)
    ],

    /** Payroll (Employee Center): akun default untuk jurnal penggajian. */
    'payroll' => [
        'salary_expense'    => '50301010101', // Biaya Gaji, Upah dan Tunjangan
        'pph21_payable'     => '20104040402', // Hutang PPh Pasal 21
        'deduction_payable' => '20107070710', // Utang Jk Pendek Lain-lain (BPJS/potongan)
    ],

    /** Contractor Management (SPK): akun default untuk jurnal progress & retensi. */
    'contractor' => [
        'construction'      => '10104010132', // CIP Housing C3 Krian (default, bisa dipilih per SPK)
        'payable'           => '20101010101', // Utang Usaha Supplier
        'retention_payable' => '20107070704', // Penyelesaian Biaya Konstruksi (retensi ditahan)
        'ppn_in'            => '10105010101', // PPN Masukan
        'material_inventory'=> '10104010101', // Persediaan (default kredit Pemakaian Barang)
    ],

    /** Purchase Order: akun default untuk Posting Termin (uang muka pembelian). */
    'purchase_order' => [
        'advance' => '10107010102', // UANG MUKA PEMBELIAN
    ],
];
