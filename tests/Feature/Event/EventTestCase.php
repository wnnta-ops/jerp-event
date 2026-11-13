<?php

namespace Tests\Feature\Event;

use App\Models\User;
use App\Modules\Event\Models\EtAccess;
use App\Modules\Event\Models\EtEvent;
use App\Modules\Event\Models\EtScanPoint;
use App\Modules\Event\Models\EtStation;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Throwable;

/**
 * Basis test modul Event — jalan di MySQL sungguhan, BUKAN SQLite :memory:.
 *
 * Kenapa: suite SQLite gagal di migrasi MySQL-only (ALTER ... MODIFY), dan
 * ScanService butuh lockForUpdate + kolom JSON yang perilakunya harus sama
 * dengan produksi.
 *
 * DB: EVENT_TEST_DB_* (default 127.0.0.1:3307, root, jerp_event_testing).
 * BENTENG: nama DB WAJIB berakhiran "_testing" — tak akan pernah menyentuh
 * jerp_approval. Isolasi per test lewat DatabaseTransactions. Bila MySQL tak
 * terjangkau, test di-skip (suite lain tetap jalan).
 */
abstract class EventTestCase extends TestCase
{
    use DatabaseTransactions;

    private static bool $siap = false;

    private static ?string $gagal = null;

    public function createApplication()
    {
        $app = require __DIR__.'/../../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        $db = (string) (getenv('EVENT_TEST_DB_DATABASE') ?: 'jerp_event_testing');
        if (! str_ends_with($db, '_testing')) {
            throw new \RuntimeException("DB test Event harus berakhiran _testing, bukan '{$db}'.");
        }

        $base = $app['config']->get('database.connections.mysql');
        $app['config']->set('database.connections.event_testing', array_merge($base, [
            'host' => getenv('EVENT_TEST_DB_HOST') ?: '127.0.0.1',
            'port' => getenv('EVENT_TEST_DB_PORT') ?: '3307',
            'database' => $db,
            'username' => getenv('EVENT_TEST_DB_USERNAME') ?: 'root',
            'password' => getenv('EVENT_TEST_DB_PASSWORD') ?: '',
        ]));
        $app['config']->set('database.default', 'event_testing');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('session.driver', 'array');
        $app['config']->set('broadcasting.default', 'null');
        $app['config']->set('activitylog.enabled', false);

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$gagal !== null) {
            $this->markTestSkipped(self::$gagal);
        }
        if (! self::$siap) {
            $this->siapkanDatabase();
        }
    }

    /**
     * Sekali per proses: migrasikan DB test. Di DB kosong, migrasi
     * register_spr_numerator butuh tenant → urutan sama dengan instal baru:
     * migrate (berhenti) → TenantSeeder → migrate.
     */
    private function siapkanDatabase(): void
    {
        try {
            DB::connection()->getPdo();
        } catch (Throwable $e) {
            self::$gagal = 'MySQL test Event tak terjangkau: '.$e->getMessage();
            $this->markTestSkipped(self::$gagal);
        }

        // DatabaseTransactions sudah membuka transaksi; DDL MySQL auto-commit. Tutup dulu.
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        try {
            Artisan::call('migrate', ['--force' => true]);
        } catch (Throwable) {
            Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\TenantSeeder', '--force' => true]);
            Artisan::call('migrate', ['--force' => true]);
        }

        if (! Schema::hasTable('et_scan_logs')) {
            throw new \RuntimeException('Migrasi et_* belum jalan di DB test.');
        }

        foreach (['super_admin', 'sup'] as $peran) {
            Role::findOrCreate($peran, 'web');
        }

        self::$siap = true;
        DB::beginTransaction();
    }

    // ── fixture FO (hanya di DB test) ──────────────────────────────────

    protected function projectId(): int
    {
        $id = DB::table('projects')->where('code', 'EVT-UJI')->value('id');
        if ($id) {
            return (int) $id;
        }
        $company = DB::table('companies')->where('code', 'EVT-UJI')->value('id')
            ?: DB::table('companies')->insertGetId(['tenant_id' => 1, 'code' => 'EVT-UJI', 'name' => 'PT Uji', 'created_at' => now(), 'updated_at' => now()]);

        return (int) DB::table('projects')->insertGetId([
            'tenant_id' => 1, 'company_id' => $company, 'code' => 'EVT-UJI', 'name' => 'Proyek Uji',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function foNupEvent(string $code = 'LNC'): int
    {
        return (int) DB::table('fo_nup_events')->insertGetId([
            'tenant_id' => 1, 'project_id' => $this->projectId(), 'code' => $code.'-'.Str::random(5), 'name' => 'Launching '.$code,
            'tgl_launching' => '2026-11-20', 'booking_fee' => 0, 'status' => 'OPEN',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function foCustomer(string $code, string $name = 'Budi Santoso'): int
    {
        return (int) DB::table('fo_customers')->insertGetId([
            'tenant_id' => 1, 'code' => $code, 'full_name' => $name, 'mobile' => '0812000',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $extra */
    protected function foNup(int $nupEventId, ?int $customerId, string $noNup, array $extra = []): int
    {
        return (int) DB::table('fo_nup_registrations')->insertGetId(array_merge([
            'tenant_id' => 1, 'nup_event_id' => $nupEventId, 'customer_id' => $customerId,
            'no_nup' => $noNup, 'nama_pendaftar' => 'pendaftar '.$noNup, 'tgl_daftar' => now(),
            'status' => 'CHECK_IN', 'tgl_check_in' => now()->subMinutes(30),
            'verif_status' => 'APPROVED', 'is_hold' => false,
            'created_at' => now(), 'updated_at' => now(),
        ], $extra));
    }

    // ── fixture Event ──────────────────────────────────────────────────

    /**
     * Event open: pos 1 FO, pos 2 "Konsultasi" (masuk+keluar), pos 3 "Pilih Unit" (finish, masuk+keluar).
     *
     * @return array{event: EtEvent, s2: EtStation, s3: EtStation, m2: EtScanPoint, k2: EtScanPoint, m3: EtScanPoint, k3: EtScanPoint}
     */
    protected function eventOpen(int $nupEventId, string $status = EtEvent::OPEN): array
    {
        $event = EtEvent::create([
            'name' => 'Pilih Unit', 'event_date' => '2026-11-20', 'fo_nup_event_id' => $nupEventId,
            'status' => $status, 'opened_at' => $status === EtEvent::OPEN ? now() : null,
        ]);
        EtStation::create(['event_id' => $event->id, 'code' => 'FO', 'name' => 'Aktivasi NUP (FO)', 'sequence' => 1, 'type' => EtStation::FO_CHECKIN]);
        $s2 = EtStation::create(['event_id' => $event->id, 'code' => 'KONS', 'name' => 'Konsultasi', 'sequence' => 2, 'type' => EtStation::NORMAL, 'target_minutes' => 10]);
        $s3 = EtStation::create(['event_id' => $event->id, 'code' => 'PU', 'name' => 'Pilih Unit', 'sequence' => 3, 'type' => EtStation::FINISH, 'target_minutes' => 15]);

        $pt = fn (EtStation $s, string $k) => EtScanPoint::create(['station_id' => $s->id, 'kind' => $k, 'label' => $s->name.' · '.strtoupper($k)]);

        return [
            'event' => $event, 's2' => $s2, 's3' => $s3,
            'm2' => $pt($s2, EtScanPoint::MASUK), 'k2' => $pt($s2, EtScanPoint::KELUAR),
            'm3' => $pt($s3, EtScanPoint::MASUK), 'k3' => $pt($s3, EtScanPoint::KELUAR),
        ];
    }

    protected function superUser(string $peran = 'super_admin'): User
    {
        $u = $this->user();
        $u->assignRole($peran);

        return $u;
    }

    protected function user(): User
    {
        return User::withoutEvents(fn () => User::forceCreate([
            'tenant_id' => 1,
            'name' => 'Uji '.Str::random(5),
            'full_name' => 'Uji Event',
            'email' => Str::random(10).'@event.test',
            'password' => bcrypt('x'),
            'is_active' => true,
        ]));
    }

    protected function grantScan(User $u, EtEvent $e, EtScanPoint $p): void
    {
        EtAccess::create(['event_id' => $e->id, 'user_id' => $u->id, 'scan_point_id' => $p->id]);
    }
}
