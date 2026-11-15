<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Data FO CONTOH untuk uji coba modul Event — KHUSUS lokal/testing.
 *
 * Satu event NUP "[DEMO-EVENT]" + 30 customer (kode DEMO-EVT-*) + 31 NUP (DEMO-*):
 *   DEMO-0001 Andi   — aktivasi
 *   DEMO-0002 Budi   — aktivasi
 *   DEMO-0003 Citra  — aktivasi  ┐ satu customer, dua NUP
 *   DEMO-0004 Citra  — aktivasi  ┘ (uji picker NUP)
 *   DEMO-0005 Dewi   — aktivasi, tapi di-HOLD
 *   DEMO-0006 Eko    — APPROVED, belum aktivasi (aktivasi sendiri di FO untuk uji sinkron)
 *   DEMO-0007 Fajar  — verifikasi masih PENDING
 *   DEMO-0008 … 0031 — 24 customer lain, sudah aktivasi 0,5–110 menit lalu
 *                      (bahan EventDemoSeeder untuk live board)
 *
 * Ditulis lewat query builder (bukan model FO) supaya observer FO — mis. webhook
 * survei pada Customer — tidak ikut terpicu. Idempoten: data demo lama dihapus dulu.
 * Hapus: php artisan db:seed --class=EventDemoFoHapusSeeder
 */
class EventDemoFoSeeder extends Seeder
{
    public const EVENT_CODE = 'DEMOEVT';

    public const CUST_PREFIX = 'DEMO-EVT-';

    public const NUP_PREFIX = 'DEMO-';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('EventDemoFoSeeder hanya untuk lingkungan lokal/testing.');
        }

        (new EventDemoFoHapusSeeder)->run();

        $projectId = DB::table('projects')->where('tenant_id', 1)->orderBy('id')->value('id');
        if (! $projectId) {
            throw new RuntimeException('Belum ada proyek untuk tenant 1.');
        }

        DB::transaction(function () use ($projectId) {
            $now = now();
            $eventId = DB::table('fo_nup_events')->insertGetId([
                'tenant_id' => 1, 'project_id' => $projectId, 'code' => self::EVENT_CODE,
                'name' => '[DEMO-EVENT] Launching uji modul Event', 'tgl_launching' => $now->toDateString(),
                'booking_fee' => 0, 'status' => 'OPEN', 'keterangan' => 'Data contoh lokal — boleh dihapus.',
                'created_at' => $now, 'updated_at' => $now,
            ]);

            $nama = ['01' => 'Andi Pratama', '02' => 'Budi Santoso', '03' => 'Citra Lestari', '04' => 'Dewi Anggraini', '05' => 'Eko Wibowo', '06' => 'Fajar Nugroho'];
            $lain = ['Gilang Ramadhan', 'Hana Putri', 'Indra Kusuma', 'Joko Susilo', 'Kartika Sari', 'Lukman Hakim', 'Maya Safitri', 'Nanda Prasetyo',
                'Oktaviani', 'Putu Ardana', 'Qori Amalia', 'Rizky Maulana', 'Sinta Dewi', 'Taufik Hidayat', 'Umi Kalsum', 'Vino Bastian',
                'Wulan Sari', 'Xaverius Tan', 'Yusuf Mansur', 'Zahra Nabila', 'Arief Budiman', 'Bella Saphira', 'Cahyo Nugroho', 'Dimas Anggara'];
            foreach ($lain as $i => $n) {
                $nama[str_pad((string) ($i + 7), 2, '0', STR_PAD_LEFT)] = $n;
            }

            $customer = [];
            foreach ($nama as $no => $nm) {
                $customer[$no] = DB::table('fo_customers')->insertGetId([
                    'tenant_id' => 1, 'project_id' => $projectId, 'code' => self::CUST_PREFIX.$no,
                    'full_name' => $nm, 'mobile' => '08120000'.$no,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }

            // [no_nup, customer, status, menit check-in yang lalu (null = belum), verif, hold]
            $nup = [
                ['0001', '01', 'CHECK_IN', 50, 'APPROVED', false],
                ['0002', '02', 'CHECK_IN', 40, 'APPROVED', false],
                ['0003', '03', 'CHECK_IN', 30, 'APPROVED', false],
                ['0004', '03', 'CHECK_IN', 30, 'APPROVED', false],
                ['0005', '04', 'CHECK_IN', 20, 'APPROVED', true],
                ['0006', '05', 'DAFTAR', null, 'APPROVED', false],
                ['0007', '06', 'DAFTAR', null, 'PENDING', false],
            ];
            // 0008–0031: sudah aktivasi, menit lalu (dipasangkan dengan rencana di EventDemoSeeder).
            $ago = [110, 100, 95, 90, 85, 80, 60, 45, 35, 40, 30, 35, 25, 20, 12, 6, 15, 9, 4, 2, 70, 75, 1, 0.5];
            foreach ($ago as $i => $menit) {
                $nup[] = [str_pad((string) ($i + 8), 4, '0', STR_PAD_LEFT), str_pad((string) ($i + 7), 2, '0', STR_PAD_LEFT), 'CHECK_IN', $menit, 'APPROVED', false];
            }
            foreach ($nup as $i => [$no, $cust, $status, $menit, $verif, $hold]) {
                DB::table('fo_nup_registrations')->insert([
                    'tenant_id' => 1, 'nup_event_id' => $eventId, 'customer_id' => $customer[$cust],
                    'no_nup' => self::NUP_PREFIX.$no, 'urutan' => $i + 1,
                    'nama_pendaftar' => DB::table('fo_customers')->where('id', $customer[$cust])->value('full_name'),
                    'tgl_daftar' => $now->copy()->subDays(7), 'status' => $status,
                    'tgl_check_in' => $menit !== null ? $now->copy()->subSeconds((int) round($menit * 60)) : null,
                    'verif_status' => $verif, 'is_hold' => $hold,
                    'hold_reason' => $hold ? 'Data contoh: di-hold' : null,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        });

        $this->command?->info('  Data FO contoh [DEMO-EVENT] siap: 1 event NUP, 30 customer, 31 NUP.');
    }
}
