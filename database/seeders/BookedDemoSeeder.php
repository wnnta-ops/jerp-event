<?php

namespace Database\Seeders;

use App\Modules\FO\Booked\Services\BookedRegistrationService;
use App\Modules\FO\Launching\Models\NupRegistration;
use Illuminate\Database\Seeder;

/**
 * Inject data DEMO: 25 customer + pendaftaran "Booked Manual" (jenis=BOOKED,
 * verif_status=MENUNGGU_BAYAR) → langsung muncul di menu "Verifikasi Data Booked".
 * Reuse BookedRegistrationService::register() supaya field & numerator (CUST) valid.
 *
 * Jalankan:  php artisan db:seed --class=BookedDemoSeeder --force
 * Idempoten: NIK demo ber-prefix 3201019999 — baris yang sudah ada dilewati (aman diulang).
 * Hapus demo: DELETE fo_nup_registrations & fo_customers yang nik LIKE '3201019999%'.
 */
class BookedDemoSeeder extends Seeder
{
    private const JUMLAH = 25;

    private const NIK_PREFIX = '3201019999'; // penanda data demo (mudah dibersihkan)

    public function run(): void
    {
        $svc = app(BookedRegistrationService::class);

        $depan = ['Andi', 'Budi', 'Citra', 'Dewi', 'Eka', 'Fajar', 'Gita', 'Hadi', 'Indah', 'Joko',
            'Kartika', 'Lukman', 'Maya', 'Nanda', 'Oki', 'Putri', 'Rizky', 'Sari', 'Toni', 'Umi',
            'Vina', 'Wawan', 'Yuni', 'Zaki', 'Bagas'];
        $belakang = ['Wijaya', 'Santoso', 'Pratama', 'Lestari', 'Kusuma', 'Nugroho', 'Anggraini',
            'Saputra', 'Halim', 'Permana', 'Hakim', 'Maulana', 'Rahayu', 'Setiawan', 'Utami'];
        $sales = ['Rendi', 'Sinta', 'Bayu', 'Nadia', 'Galih'];
        $cara = ['TRANSFER', 'CASH', 'KPR'];
        $unitHint = ['Cluster A - Tipe 36', 'Cluster B - Tipe 45', 'Cluster C - Tipe 60', 'Hook Cluster A', 'Tipe 36 depan taman'];

        $dibuat = 0;
        $dilewati = 0;

        for ($i = 1; $i <= self::JUMLAH; $i++) {
            $nama = $depan[($i - 1) % count($depan)].' '.$belakang[($i * 3) % count($belakang)];
            $nik = self::NIK_PREFIX.str_pad((string) $i, 6, '0', STR_PAD_LEFT); // 16 digit, unik

            // Idempoten: kalau sudah ada pendaftaran BOOKED utk NIK ini, lewati.
            if (NupRegistration::where('nik', $nik)->where('jenis', 'BOOKED')->exists()) {
                $dilewati++;

                continue;
            }

            $svc->register([
                'tenant_id' => 1,
                'full_name' => $nama,
                'nik' => $nik,
                'phone' => '0812'.str_pad((string) (34500000 + $i), 8, '0', STR_PAD_LEFT),
                'mobile' => '0812'.str_pad((string) (34500000 + $i), 8, '0', STR_PAD_LEFT),
                'email' => strtolower(str_replace(' ', '.', $nama)).$i.'@demo.test',
                'birth_place' => 'Jakarta',
                'birth_date' => sprintf('19%02d-%02d-%02d', 80 + ($i % 15), ($i % 12) + 1, ($i % 27) + 1),
                'gender' => $i % 2 ? 'L' : 'P',
                'marital_status' => $i % 3 ? 'KAWIN' : 'BELUM',
                'religion' => 'ISLAM',
                'city' => 'Jakarta',
                'province' => 'DKI Jakarta',
                'occupation' => 'Karyawan Swasta',
                'cara_bayar' => $cara[$i % count($cara)],
                'pilihan_unit_1' => $unitHint[$i % count($unitHint)],
                'ref_sales_name' => 'Sales '.$sales[$i % count($sales)],
                'ref_sales_phone' => '0813'.str_pad((string) (10000000 + $i), 8, '0', STR_PAD_LEFT),
                'channel' => 'DEMO',
                'pernyataan_setuju' => true,
            ]);
            $dibuat++;
        }

        $this->command?->info("BookedDemoSeeder: {$dibuat} pendaftaran booked baru, {$dilewati} dilewati (sudah ada). Cek di menu Verifikasi Data Booked.");
    }
}
