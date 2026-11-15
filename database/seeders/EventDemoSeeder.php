<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Event\Models\EtEvent;
use App\Modules\Event\Models\EtParticipant;
use App\Modules\Event\Models\EtScanPoint;
use App\Modules\Event\Services\EventSetupService;
use App\Modules\Event\Services\FoNupLookup;
use App\Modules\Event\Services\KoreksiService;
use App\Modules\Event\Services\ScanService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Event CONTOH yang sedang berjalan — untuk melihat live board. KHUSUS lokal/testing.
 *
 * 1. Membuat ulang data FO contoh (EventDemoFoSeeder: 28 NUP sudah aktivasi).
 * 2. Membuat event "[DEMO] Pilih Unit — live board" (terhubung DEMOEVT):
 *    Konsultasi (10 mnt) → Validasi Berkas (8 mnt, opsional) → Pilih Unit (15 mnt) → Merchandise (5 mnt, Selesai).
 * 3. Memutar ulang perjalanan peserta lewat ScanService dengan jam mundur, sehingga
 *    ada yang sudah selesai, sedang dilayani (sebagian lewat target), menunggu,
 *    melewati pos opsional, dan satu yang pulang.
 *
 * Semua data (event & FO) berlabel DEMO dan dibuat ulang setiap dijalankan.
 *   php artisan db:seed --class=EventDemoSeeder
 */
class EventDemoSeeder extends Seeder
{
    public const NAMA = '[DEMO] Pilih Unit — live board';

    /**
     * Rencana per No. NUP: daftar [titik, menit setelah check-in]. 'pulang' = koreksi tandai pulang.
     * Titik: m/k = masuk/keluar; 2 Konsultasi, V Validasi (opsional), 3 Pilih Unit, 4 Merchandise.
     */
    private const RENCANA = [
        // Selesai lewat Validasi
        'DEMO-0001' => [['m2', 2], ['k2', 11], ['mV', 13], ['kV', 19], ['m3', 22], ['k3', 35], ['m4', 37], ['k4', 42]],
        'DEMO-0008' => [['m2', 2], ['k2', 11], ['mV', 13], ['kV', 19], ['m3', 22], ['k3', 35], ['m4', 37], ['k4', 42]],
        'DEMO-0009' => [['m2', 3], ['k2', 14], ['mV', 15], ['kV', 22], ['m3', 25], ['k3', 41], ['m4', 43], ['k4', 47]],
        'DEMO-0010' => [['m2', 1], ['k2', 9], ['mV', 12], ['kV', 18], ['m3', 20], ['k3', 33], ['m4', 34], ['k4', 39]],
        // Selesai, melewati Validasi (opsional)
        'DEMO-0011' => [['m2', 2], ['k2', 12], ['m3', 15], ['k3', 30], ['m4', 31], ['k4', 36]],
        'DEMO-0012' => [['m2', 4], ['k2', 13], ['m3', 16], ['k3', 29], ['m4', 30], ['k4', 34]],
        'DEMO-0013' => [['m2', 2], ['k2', 10], ['mV', 12], ['kV', 17], ['m3', 19], ['k3', 31], ['m4', 33], ['k4', 37]],
        'DEMO-0029' => [['m2', 2], ['k2', 10], ['m3', 12], ['k3', 25], ['m4', 27], ['k4', 31]],
        // Sedang dilayani di Pilih Unit (40 mnt → merah, 15 → kuning, 10 → normal)
        'DEMO-0014' => [['m2', 2], ['k2', 11], ['m3', 20]],
        'DEMO-0015' => [['m2', 2], ['k2', 11], ['m3', 30]],
        'DEMO-0016' => [['m2', 2], ['k2', 11], ['mV', 13], ['kV', 20], ['m3', 25]],
        // Menunggu Pilih Unit
        'DEMO-0002' => [['m2', 2], ['k2', 11], ['mV', 13], ['kV', 19]],
        'DEMO-0017' => [['m2', 3], ['k2', 12]],
        'DEMO-0018' => [['m2', 2], ['k2', 11], ['mV', 13], ['kV', 20]],
        // Sedang di Validasi Berkas (opsional)
        'DEMO-0019' => [['m2', 2], ['k2', 12], ['mV', 14]],
        'DEMO-0020' => [['m2', 2], ['k2', 10], ['mV', 14]],
        // Sedang dilayani di Konsultasi (Citra: NUP 0003 dilayani, NUP 0004 menunggu)
        'DEMO-0003' => [['m2', 15]],
        'DEMO-0021' => [['m2', 2]],
        'DEMO-0022' => [['m2', 1]],
        'DEMO-0023' => [['m2', 1]],
        // Pulang setelah Konsultasi
        'DEMO-0028' => [['m2', 2], ['k2', 10], ['pulang', 20]],
        // Sisanya (0004, 0024–0027, 0030, 0031) menunggu di Konsultasi.
    ];

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('EventDemoSeeder hanya untuk lingkungan lokal/testing.');
        }

        // Event demo lama ikut terhapus (FK cascade ke pos, peserta, kunjungan, akses, log).
        EtEvent::query()->withTrashed()->where('name', self::NAMA)->get()->each(fn (EtEvent $e) => $e->forceDelete());
        $this->call(EventDemoFoSeeder::class);

        $super = User::query()->where('email', 'event.super@jerp.test')->first()
            ?? User::role('super_admin')->orderBy('id')->first()
            ?? throw new RuntimeException('Tidak ada akun super_admin. Jalankan EventUjiSeeder dulu.');
        $scanner = User::query()->where('email', 'event.scanner@jerp.test')->first();

        $nupEvent = app(FoNupLookup::class)->nupEvents()->firstWhere('code', EventDemoFoSeeder::EVENT_CODE)
            ?? throw new RuntimeException('Event NUP demo tidak ditemukan.');

        $svc = app(EventSetupService::class);
        $pos = [
            ['code' => 'KONS', 'name' => 'Konsultasi', 'target_minutes' => 10],
            ['code' => 'VAL', 'name' => 'Validasi Berkas', 'target_minutes' => 8, 'is_optional' => true],
            ['code' => 'PU', 'name' => 'Pilih Unit', 'target_minutes' => 15],
            ['code' => 'MERCH', 'name' => 'Merchandise', 'target_minutes' => 5],
        ];
        $titik = $svc->titikBawaan($pos);
        $semuaTitik = array_keys($svc->pilihanTitik($titik));

        $event = $svc->create([
            'jenis' => EventSetupService::JENIS_FO,
            'name' => self::NAMA, 'event_date' => now()->toDateString(), 'venue' => 'Marketing Gallery (demo)',
            'fo_nup_event_id' => $nupEvent['id'], 'pos' => $pos, 'titik' => $titik,
            'akses' => $scanner ? [['user_id' => $scanner->id, 'titik' => $semuaTitik, 'can_live_board' => true, 'can_reports' => true]] : [],
            'buka_sekarang' => true,   // membuka event = menarik 28 NUP yang sudah aktivasi
        ], $super);

        $titikId = fn (string $code, string $kind) => (int) EtScanPoint::query()
            ->whereHas('station', fn ($q) => $q->where('event_id', $event->id)->where('code', $code))
            ->where('kind', $kind)->value('id');
        $peta = [
            'm2' => $titikId('KONS', 'masuk'), 'k2' => $titikId('KONS', 'keluar'),
            'mV' => $titikId('VAL', 'masuk'), 'kV' => $titikId('VAL', 'keluar'),
            'm3' => $titikId('PU', 'masuk'), 'k3' => $titikId('PU', 'keluar'),
            'm4' => $titikId('MERCH', 'masuk'), 'k4' => $titikId('MERCH', 'keluar'),
        ];

        $sekarang = now();
        $gagal = [];
        try {
            foreach (self::RENCANA as $noNup => $langkah) {
                $p = EtParticipant::query()->where('event_id', $event->id)->where('no_nup', $noNup)->first();
                if (! $p?->checked_in_at) {
                    $gagal[] = $noNup.' (belum tersinkron)';

                    continue;
                }
                foreach ($langkah as [$aksi, $menit]) {
                    $waktu = $p->checked_in_at->copy()->addSeconds((int) round($menit * 60));
                    Carbon::setTestNow($waktu->min($sekarang));
                    if ($aksi === 'pulang') {
                        app(KoreksiService::class)->tandaiPulang($p, $super, 'Data demo: customer pulang lebih awal');

                        continue;
                    }
                    $r = app(ScanService::class)->handle($peta[$aksi], $noNup, $scanner ?? $super, null, 'EventDemoSeeder');
                    if (! $r->ok()) {
                        $gagal[] = "{$noNup} {$aksi}: {$r->message}";
                    }
                }
            }
        } finally {
            Carbon::setTestNow();
        }

        $this->command?->info('  Event demo "'.self::NAMA.'" dibuka: '.$event->participants()->count().' peserta.');
        foreach ($gagal as $g) {
            $this->command?->warn('  Lewati: '.$g);
        }
    }
}
