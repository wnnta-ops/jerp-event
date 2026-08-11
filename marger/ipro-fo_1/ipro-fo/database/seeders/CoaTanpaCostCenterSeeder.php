<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Daftar Chart of Account yang BOLEH dijurnal tanpa cost center.
 *
 * Sumber: lampiran "COA tanpa Cost Center.xlsx" dari pemilik proses (71 akun). Umumnya akun neraca
 * (bank, piutang, akumulasi penyusutan, utang, ekuitas) ditambah beberapa akun laba-rugi yang memang
 * tidak dibebankan per cost center.
 *
 * Idempoten: dicocokkan berdasarkan KODE akun, aman dijalankan berulang kali. Akun di luar daftar
 * ini dikembalikan menjadi wajib cost center, sehingga daftar di bawah adalah satu-satunya sumber
 * kebenaran — mencabut satu baris di sini otomatis mengembalikan akun itu jadi wajib.
 */
class CoaTanpaCostCenterSeeder extends Seeder
{
    /** Kode akun => nama akun sesuai lampiran (nama hanya untuk rujukan saat ditinjau manusia). */
    public const CODES = [
        '10101020201' => 'BTN REK.393-01-30-000374-9',
        '10101020202' => 'BNI REK. 1413375985',
        '10101020203' => 'BNI REK. 1436224713',
        '10101020205' => 'BCA REK. 6155899909',
        '10101020206' => 'BRI REK. 0086 01 003982302',
        '10101020207' => 'BTN SYARIAH REK. 1000001709',
        '10101020208' => 'BCA REK. 7205529888',
        '10101020209' => 'BPR DELTA ARTHA PERSERODA REK. 1011228765',
        '10101020210' => 'MANDIRI REK. 141-00-9988886-9',
        '10101020211' => 'BCA REK. 7205990666',
        '10101020212' => 'BCA REK. 7205836888',
        '10101020213' => 'BTN ESCROW REK. 00000393-01-32-000-398-1',
        '10101020214' => 'BTN REK 00000393-01-66-000279-2',
        '10101020299' => 'AYAT (POS) SILANG',
        '10101030301' => 'DEPOSITO',
        '10101040401' => 'REKSADANA',
        '10102010101' => 'PIUTANG USAHA',
        '10104010101' => 'BANGUNAN SIAP JUAL',
        '10105020301' => 'DEPOSIT PAJAK',
        '10106010101' => 'ASURANSI DIBAYAR DIMUKA',
        '10106020201' => 'SEWA DIBAYAR DIMUKA',
        '10106040403' => 'BIAYA DIBAYAR DIMUKA LAINNYA',
        '10107030303' => 'UANG MUKA OPERASIONAL',
        '10108010101' => 'DANA ESCROW BTN',
        '10108010102' => 'DANA ESCROW BRI',
        '10108010103' => 'DANA ESCROW BTN SYARIAH',
        '10108010104' => 'DANA ESCROW BNI',
        '10201010105' => 'BANGUNAN TIDAK PERMANEN',
        '10203030301' => 'AKUMULASI PENYUSUTAN BANGUNAN',
        '10203030302' => 'AKUMULASI PENYUSUTAN KENDARAAN',
        '10203030303' => 'AKUMULASI PENYUSUTAN INVENTARIS KANTOR',
        '10203030304' => 'AKUMULASI PENYUSUTAN BANGUNAN TIDAK PERMANEN',
        '10204040401' => 'AKUMULASI AMORTISASI SOFTWARE',
        '20101010101' => 'UTANG USAHA SUPPLIER',
        '20101010102' => 'UTANG USAHA CUSTOMER',
        '20101010181' => 'TITIPAN SEMENTARA',
        '20102020201' => 'UTANG BANK JANGKA PENDEK - BCA',
        '20102020202' => 'UTANG BANK JANGKA PENDEK - BPR DELTA ARTHA',
        '20102020203' => 'UTANG BANK JANGKA PENDEK - BTN',
        '20103030301' => 'UTANG LEASING JANGKA PENDEK',
        '20104040406' => 'PPN KELUARAN',
        '20104040407' => 'PPN MASUKAN BELUM DITAGIHKAN',
        '20105050501' => 'BEBAN MASIH HARUS DIBAYAR',
        '20106060601' => 'UANG MUKA PENJUALAN',
        '20107070701' => 'HUTANG ESCROW BTN',
        '20107070702' => 'HUTANG ESCROW BRI',
        '20107070703' => 'HUTANG ESCROW BTN SYARIAH',
        '20107070709' => 'UTANG DEVIDEN',
        '20107070710' => 'UTANG JK. PENDEK LAIN-LAIN',
        '20107070712' => 'HUTANG ESCROW BNI',
        '20107070723' => 'PENYELESAIAN BIAYA KONSTRUKSI JRK CLUSTER 6',
        '20201010101' => 'UTANG BANK JANGKA PANJANG - BCA',
        '20201010102' => 'UTANG BANK JANGKA PANJANG - BPR DELTA ARTHA',
        '20202020201' => 'UTANG LEASING JANGKA PANJANG',
        '30102020201' => 'LABA/RUGI PERIODE BERJALAN',
        '30103030301' => 'LABA/RUGI TAHUN SEBELUMNYA',
        '40101010101' => 'PENJUALAN USAHA',
        '40102020201' => 'DISKON PENJUALAN',
        '50101010101' => 'BEBAN POKOK PENJUALAN',
        '50201010102' => 'BIAYA IKLAN & MATERIAL PROMO',
        '50201010103' => 'KOMISI/INSENTIF PENJUALAN',
        '50302020225' => 'BIAYA PENYUSUTAN AT BANGUNAN',
        '50302020226' => 'BIAYA PENYUSUTAN AT KENDARAAN',
        '50302020227' => 'BIAYA PENYUSUTAN AT INVENTARIS KANTOR',
        '50302020228' => 'BIAYA UMUM & ADMINISTRASI LAIN-LAIN',
        '50302020229' => 'AMORTISASI PENYUSUTAN SOFTWARE',
        '50302020230' => 'BIAYA PENYUSUTAN AT BANGUNAN TIDAK PERMANEN',
        '60101010101' => 'PENDAPATAN BUNGA & JASA KEUANGAN',
        '60101010102' => 'PENDAPATAN REKSADANA',
        '70101010104' => 'PPH PASAL 4 (2) ATAS PENJUALAN TANAH DAN BANGUNAN',
        '70101010112' => 'BEBAN DILUAR USAHA LAIN-LAIN',
    ];

    public function run(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasColumn('bs_accounts', 'cost_center_optional')) {
            return;
        }

        $codes = array_keys(self::CODES);

        // Dua langkah agar daftar di atas menjadi satu-satunya sumber kebenaran:
        // yang di luar daftar dikembalikan wajib, yang di dalam daftar dibebaskan.
        DB::table('bs_accounts')->whereNotIn('code', $codes)->where('cost_center_optional', true)
            ->update(['cost_center_optional' => false]);
        DB::table('bs_accounts')->whereIn('code', $codes)
            ->update(['cost_center_optional' => true]);

        // Dihitung ulang dari hasil akhir, BUKAN dari jumlah baris yang berubah — kalau memakai
        // nilai kembalian update(), menjalankan seeder kedua kali akan melaporkan "0 ditandai"
        // padahal seluruh akunnya sudah bertanda.
        $cocok = DB::table('bs_accounts')->whereIn('code', $codes)->where('cost_center_optional', true)->count();
        $hilang = array_values(array_diff($codes, DB::table('bs_accounts')->whereIn('code', $codes)->pluck('code')->all()));

        $this->command?->info("CoA tanpa cost center: {$cocok} dari ".count($codes).' kode bertanda.');
        if ($hilang) {
            $this->command?->warn('  Kode tidak ada di Chart of Account: '.implode(', ', $hilang));
        }
    }
}
