<?php

namespace Database\Seeders;

use App\Modules\BO\GeneralLedger\Models\ChartOfAccount;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Standard chart of accounts for a property developer (id-ID).
 * Header accounts (is_header=true) cannot be posted to directly.
 *
 * Row format: [kode, nama, tipe_akun, level, is_header, ?saldo_normal, ?is_pajak]
 */
class BoChartOfAccountSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            // 1 — ASET
            ['1',           'ASET',                          'ASSET', 1, true],
            ['1.1',         'Aset Lancar',                   'ASSET', 2, true],
            ['1.1.01',      'Kas & Bank',                    'ASSET', 3, true],
            ['1.1.01.001',  'Kas Kecil',                     'ASSET', 4, false],
            ['1.1.01.002',  'Kas Besar',                     'ASSET', 4, false],
            ['1.1.01.101',  'Bank BCA',                      'ASSET', 4, false],
            ['1.1.01.102',  'Bank Mandiri',                  'ASSET', 4, false],
            ['1.1.01.103',  'Bank BNI',                      'ASSET', 4, false],
            ['1.1.01.104',  'Bank BTN',                      'ASSET', 4, false],
            ['1.1.02',      'Piutang',                       'ASSET', 3, true],
            ['1.1.02.001',  'Piutang Customer',              'ASSET', 4, false],
            ['1.1.02.002',  'Piutang Retensi KPR',           'ASSET', 4, false],
            ['1.1.02.003',  'Piutang Lain-lain',             'ASSET', 4, false],
            ['1.1.02.004',  'PPN Masukan',                   'ASSET', 4, false, 'DEBET', true],
            ['1.1.03',      'Persediaan',                    'ASSET', 3, true],
            ['1.1.03.001',  'Persediaan Tanah',              'ASSET', 4, false],
            ['1.1.03.002',  'Persediaan Unit/Bangunan',      'ASSET', 4, false],
            ['1.1.03.003',  'Persediaan Material',           'ASSET', 4, false],
            ['1.1.04',      'Titipan & Dana Anonim',         'ASSET', 3, true],
            ['1.1.04.001',  'Dana Belum Teridentifikasi',    'ASSET', 4, false],
            ['1.2',         'Aset Tetap',                    'ASSET', 2, true],
            ['1.2.01.001',  'Tanah',                         'ASSET', 4, false],
            ['1.2.01.002',  'Bangunan Kantor',               'ASSET', 4, false],
            ['1.2.01.003',  'Kendaraan',                     'ASSET', 4, false],
            ['1.2.01.004',  'Inventaris Kantor',             'ASSET', 4, false],
            ['1.2.02.001',  'Akumulasi Penyusutan',          'ASSET', 4, false, 'KREDIT'],

            // 2 — LIABILITAS
            ['2',           'LIABILITAS',                    'LIABILITY', 1, true],
            ['2.1',         'Hutang Lancar',                 'LIABILITY', 2, true],
            ['2.1.01.001',  'Hutang Usaha (Supplier)',       'LIABILITY', 4, false],
            ['2.1.01.002',  'Hutang PPN Keluaran',           'LIABILITY', 4, false, 'KREDIT', true],
            ['2.1.01.003',  'Hutang PPh',                    'LIABILITY', 4, false, 'KREDIT', true],
            ['2.1.01.004',  'Uang Muka Penjualan',           'LIABILITY', 4, false],
            ['2.1.01.005',  'Hutang Giro',                   'LIABILITY', 4, false],
            ['2.2.01.001',  'Hutang Bank/KPR',               'LIABILITY', 4, false],

            // 3 — EKUITAS
            ['3',           'EKUITAS',                       'EQUITY', 1, true],
            ['3.1.01.001',  'Modal Disetor',                 'EQUITY', 4, false],
            ['3.1.02.001',  'Laba Ditahan',                  'EQUITY', 4, false],
            ['3.1.03.001',  'Laba/Rugi Tahun Berjalan',      'EQUITY', 4, false],

            // 4 — PENDAPATAN
            ['4',           'PENDAPATAN',                    'REVENUE', 1, true],
            ['4.1.01.001',  'Pendapatan Penjualan Unit',     'REVENUE', 4, false],
            ['4.1.01.002',  'Pendapatan Penjualan Tanah',    'REVENUE', 4, false],
            ['4.1.02.001',  'Pendapatan Booking Fee',        'REVENUE', 4, false],
            ['4.1.03.001',  'Pendapatan Denda',              'REVENUE', 4, false],
            ['4.1.04.001',  'Pendapatan Lain-lain',          'REVENUE', 4, false],

            // 5 — BEBAN
            ['5',           'BEBAN',                         'EXPENSE', 1, true],
            ['5.1.01.001',  'Beban Pokok Penjualan (HPP)',   'EXPENSE', 4, false],
            ['5.2.01.001',  'Beban Komisi Sales',            'EXPENSE', 4, false],
            ['5.2.01.002',  'Beban Marketing & Promosi',     'EXPENSE', 4, false],
            ['5.2.02.001',  'Beban Gaji & Tunjangan',        'EXPENSE', 4, false],
            ['5.2.02.002',  'Beban Operasional Kantor',      'EXPENSE', 4, false],
            ['5.2.03.001',  'Beban Administrasi Bank',       'EXPENSE', 4, false],
            ['5.2.03.002',  'Beban Bunga',                   'EXPENSE', 4, false],
            ['5.2.04.001',  'Beban Penyusutan',              'EXPENSE', 4, false],
            ['5.9.01.001',  'Beban Lain-lain',               'EXPENSE', 4, false],
        ];

        foreach ($rows as $r) {
            [$kode, $nama, $tipe, $level, $isHeader] = $r;
            $saldo = $r[5] ?? ChartOfAccount::normalBalanceFor($tipe);
            $isPajak = $r[6] ?? false;

            ChartOfAccount::firstOrCreate(
                ['tenant_id' => 1, 'kode' => $kode],
                [
                    'tenant_id' => 1,
                    'nama' => $nama,
                    'tipe_akun' => $tipe,
                    'saldo_normal' => $saldo,
                    'level' => $level,
                    'parent_kode' => $level > 1 ? Str::beforeLast($kode, '.') : null,
                    'is_header' => $isHeader,
                    'is_pajak' => $isPajak,
                    'is_active' => true,
                ]
            );
        }
    }
}
