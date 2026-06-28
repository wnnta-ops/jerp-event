<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsArCategory;
use App\Domain\Foundation\Tenant\Models\Tenant;
use Illuminate\Database\Seeder;

/**
 * Kategori Invoice AR riil eProperty (kategori → akun pendapatan + akun piutang).
 */
class ArCategorySeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::whereRaw('LOWER(code)=?', ['mup'])->first() ?? Tenant::orderBy('id')->first();
        if (! $tenant) {
            return;
        }
        $acc  = fn (string $code) => BsAccount::where('code', $code)->value('id');
        $piutang     = $acc('10102010101'); // Piutang Usaha
        $piutangGiro = $acc('10102010102'); // Piutang Giro
        if (! $piutang) {
            return;
        }

        // [kode, nama, kode_akun_pendapatan, kode_akun_piutang]
        $categories = [
            ['PENJUALAN-UNIT', 'PENJUALAN UNIT / RUMAH', '40101010101', '10102010101'],
            ['UANG-MUKA-UNIT', 'UANG MUKA / DP UNIT', '40101010101', '10102010101'],
            ['ANGSURAN-UNIT', 'ANGSURAN UNIT', '40101010101', '10102010101'],
            ['PELUNASAN-UNIT', 'PELUNASAN UNIT', '40101010101', '10102010101'],
            ['SALDO-AWAL-PIUTANG', 'SALDO AWAL PIUTANG', '40101010101', '10102010101'],
            ['DENDA-KETERLAMBATAN', 'DENDA KETERLAMBATAN', '40201010101', '10102010101'],
            ['BIAYA-ADMINISTRASI', 'BIAYA ADMINISTRASI', '40201010101', '10102010101'],
            ['BIAYA-BALIK-NAMA', 'BIAYA BALIK NAMA (BBN)', '40201010101', '10102010101'],
            ['BIAYA-IPL', 'IURAN PEMELIHARAAN LINGKUNGAN (IPL)', '40201010101', '10102010101'],
            ['BIAYA-SAMBUNGAN', 'BIAYA SAMBUNGAN LISTRIK & AIR', '40201010101', '10102010101'],
            ['PENDAPATAN-SEWA', 'PENDAPATAN SEWA', '40201010101', '10102010101'],
            ['PENJUALAN-LAIN', 'PENJUALAN DILUAR USAHA / LAIN-LAIN', '40201010101', '10102010101'],
            ['PIUTANG-GIRO', 'TAGIHAN VIA GIRO', '40201010101', '10102010102'],
        ];

        foreach ($categories as [$code, $name, $incomeCode, $rcvCode]) {
            $income = $acc($incomeCode);
            $rcv    = $acc($rcvCode) ?? $piutang;
            if (! $income) {
                continue; // akun belum di-seed → lewati
            }
            BsArCategory::updateOrCreate(
                ['tenant_id' => $tenant->id, 'code' => $code],
                ['name' => $name, 'income_account_id' => $income, 'receivable_account_id' => $rcv, 'is_active' => true],
            );
        }
    }
}
