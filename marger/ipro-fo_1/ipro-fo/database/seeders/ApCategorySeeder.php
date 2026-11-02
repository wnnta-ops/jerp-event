<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsApBerkas;
use App\Domain\BusinessSupport\Models\BsApCategory;
use App\Domain\Foundation\Tenant\Models\Tenant;
use Illuminate\Database\Seeder;

/**
 * Kategori Invoice AP riil eProperty (kategori → akun debit). Hutang selalu Utang Usaha Supplier.
 */
class ApCategorySeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::whereRaw('LOWER(code)=?', ['mup'])->first() ?? Tenant::orderBy('id')->first();
        if (! $tenant) {
            return;
        }
        $payable = BsAccount::where('code', '20101010101')->value('id');
        if (! $payable) {
            return;
        }
        $acc = fn (string $code) => BsAccount::where('code', $code)->value('id');

        // [kode, nama, kode_akun_debit]
        $categories = [
            ['PERLENGKAPAN-KANTOR', 'PERLENGKAPAN KANTOR (DIBAWAH 500RB)', '50302020219'],
            ['GAJI-KARYAWAN', 'GAJI KARYAWAN', '50301010101'],
            ['MAKAN-KARYAWAN', 'MAKAN ATAU KONSUMSI KARYAWAN', '50301010108'],
            ['RECRUITMENT-KARYAWAN', 'RECRUITMENT KARYAWAN', '50301010111'],
            ['PELATIHAN-KARYAWAN', 'BIAYA PELATIHAN KARYAWAN', '50301010110'],
            ['TUNJANGAN-LAINNYA', 'BIAYA TUNJANGAN LAINNYA', '50301010105'],
            ['JASA-KONSULTAN', 'JASA KONSULTAN', '50302020201'],
            ['JASA-APPRAISAL', 'JASA APPRAISAL', '50302020202'],
            ['JASA-NOTARIS', 'JASA NOTARIS SELAIN PEMBELIAN DAN PENJUALAN', '50302020203'],
            ['JASA-AUDIT', 'JASA AUDIT', '50302020204'],
            ['JASA-PMLHRAAN-GEDUNG', 'PEMELIHARAAN GEDUNG (JASA)', '50302020207'],
            ['JASA-PMLHRN-KENDRAAN', 'PEMELIHARAAN KENDARAAN (JASA)', '50302020210'],
            ['JASA-PMLHRN-INV-KNTR', 'PEMELIHARAAN INVENTARIS KANTOR (JASA)', '50302020212'],
            ['BIAYA-PMLHRN-GEDUNG', 'BIAYA PEMELIHARAAN GEDUNG', '50302020207'],
            ['BIAYA-PMLHRN-KNDRAAN', 'BIAYA PEMELIHARAAN KENDARAAN', '50302020209'],
            ['BIAYA-PMLHRN-INVNTRS', 'BIAYA PEMELIHARAAN INVENTARIS KANTOR', '50302020211'],
            ['OUTSOURCE-OB', 'JASA OUTSOURCE OB', '50302020213'],
            ['OUTSOURCE-DRIVER', 'JASA OUTSOURCE DRIVER', '50302020213'],
            ['OUTSOURCE-SECURITY', 'JASA OUTSOURCE SECURITY', '50302020213'],
            ['BBM-ETOLL-PARKIR', 'BBM, ETOLL, PARKIR (JASA TRANSPORTASI)', '50302020215'],
            ['PERJALANAN-DINAS', 'PERJALANAN DINAS', '50302020216'],
            ['BIAYA-LISTRIK-KANTOR', 'BIAYA LISTRIK KANTOR', '50302020217'],
            ['BIAYA-AIR-KANTOR', 'BIAYA AIR KANTOR', '50302020217'],
            ['BIAYA-INTERNET', 'BIAYA INTERNET', '50302020218'],
            ['BIAYA-TELP-PULSA', 'BIAYA TELP & PULSA', '50302020218'],
            ['BIAYA-WEB-HOSTING', 'BIAYA WEB HOSTING', '50302020218'],
            ['RUMAH-TANGGA-KANTOR', 'RUMAH TANGGA KANTOR', '50302020220'],
            ['STNK-KIR-PAJAK', 'BIAYA STNK, KIR & PAJAK', '50302020221'],
            ['RETRIBUSI-DAN-IURAN', 'RETRIBUSI DAN IURAN', '50302020222'],
            ['SUMBANGAN', 'SUMBANGAN', '50302020223'],
            ['JAMUAN', 'JAMUAN', '50302020224'],
            ['BUNGA-LEASING-BANK', 'BUNGA LEASING DAN PINJAMAN BANK', '70101010101'],
            ['ADMIN-BANK', 'ADMIN BANK', '70101010102'],
            ['PBB-KANTOR', 'PBB-KANTOR', '70101010106'],
            ['ASURANSI-DIMUKA', 'ASURANSI DIBAYAR DIMUKA', '10106010101'],
            ['SEWA-DIBAYAR-DIMUKA', 'SEWA DIBAYAR DIMUKA', '10106020201'],
            ['UM-PEMBELIAN-TANAH', 'UM PEMBELIAN TANAH', '10107010101'],
            ['UM-PEMBELIAN', 'UM PEMBELIAN', '10107010102'],
            ['UM-PEMBELIAN-OPRSNAL', 'UM PEMBELIAN OPERASIONAL', '10107010102'],
            ['BANGUNAN-KANTOR', 'BANGUNAN ATAU KANTOR', '10201010102'],
            ['KENDARAAN-KANTOR', 'KENDARAAN KANTOR', '10201010103'],
            ['INVENTARIS-KANTOR', 'INVENTARIS KANTOR', '10201010104'],
            ['SOFTWARE', 'SOFTWARE (ASET TIDAK BERWUJUD)', '10202020201'],
            ['TANAH-C1', 'TANAH C1', '20107070704'],
            ['KONSTRUKSI-INFRA-C1', 'KONSTRUKSI & INFRASTRUKTUR C1', '20107070704'],
            ['LICENSE-PERMIT-C1', 'LICENSE & PERMIT C1', '20107070704'],
            ['TANAH-C2', 'TANAH C2', '20107070705'],
            ['KONSTRUKSI-INFRA-C2', 'KONSTRUKSI & INFRASTRUKTUR C2', '20107070705'],
            ['TANAH-C3-KRIAN', 'TANAH C3 KRIAN', '20107070706'],
            ['KONSTRUKSI-INFRA-C3', 'KONSTRUKSI & INFRASTRUKTUR C3 KRIAN', '20107070706'],
            ['LICENSE-PERMIT-C3-KR', 'LICENSE & PERMIT C3 KRIAN', '20107070706'],
            ['TANAH-C4-SUKODONO', 'TANAH C4 SUKODONO', '20107070707'],
            ['KONSTRUKSI-INFRA-C4', 'KONSTRUKSI & INFRASTRUKTUR C4 SUKODONO', '20107070707'],
            ['HUTANG-PPH', 'HUTANG PPH', '20104040401'],
            ['PIUTANG-PS', 'PIUTANG PS', '10103010101'],
            ['PIUTANG-LAIN', 'PIUTANG LAIN', '10103010103'],
        ];

        foreach ($categories as [$code, $name, $debitCode]) {
            $debit = $acc($debitCode);
            if (! $debit) {
                continue; // akun belum di-seed → lewati
            }
            BsApCategory::updateOrCreate(
                ['tenant_id' => $tenant->id, 'code' => $code],
                ['name' => $name, 'debit_account_id' => $debit, 'payable_account_id' => $payable, 'is_active' => true],
            );
        }

        // Kelengkapan Berkas (dokumen wajib invoice).
        $berkas = [
            ['INVOICE', 'Invoice / Nota Supplier', true],
            ['KUITANSI', 'Kuitansi', true],
            ['FAKTUR-PAJAK', 'Faktur Pajak', false],
            ['SURAT-JALAN', 'Surat Jalan / BAP', false],
            ['PO-SPK', 'PO / SPK', false],
            ['MOU', 'MOU / Perjanjian', false],
            ['SERAH-TERIMA', 'Bukti Serah Terima', false],
        ];
        foreach ($berkas as [$code, $name, $mandatory]) {
            BsApBerkas::updateOrCreate(
                ['tenant_id' => $tenant->id, 'code' => $code],
                ['name' => $name, 'is_mandatory' => $mandatory, 'is_active' => true],
            );
        }
    }
}
