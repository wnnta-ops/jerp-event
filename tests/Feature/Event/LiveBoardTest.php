<?php

namespace Tests\Feature\Event;

use App\Filament\Event\Pages\LiveBoard;
use App\Models\User;
use App\Modules\Event\Models\EtAccess;
use App\Modules\Event\Models\EtEvent;
use App\Modules\Event\Models\EtParticipant;
use App\Modules\Event\Models\EtScanLog;
use App\Modules\Event\Models\EtVisit;
use App\Modules\Event\Services\AntreanService;
use App\Modules\Event\Services\KoreksiService;
use App\Modules\Event\Services\ScanService;
use Database\Seeders\EventDemoSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use RuntimeException;

class LiveBoardTest extends EventTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('event'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_board_data_and_page_render(): void
    {
        $ev = $this->foNupEvent();
        $this->foNup($ev, $this->foCustomer('C-LB1', 'Lia'), '9001');
        $this->foNup($ev, $this->foCustomer('C-LB2', 'Rio'), '9002');
        $e = $this->eventOpen($ev);
        $u = $this->superUser();
        app(ScanService::class)->handle($e['m2']->id, '9001', $u);
        app(ScanService::class)->handle($e['k2']->id, '9002', $u);

        $papan = app(AntreanService::class)->papan($e['event']);
        $this->assertSame(['Konsultasi', 'Pilih Unit'], array_column($papan['pos'], 'name'));
        $this->assertSame(['9001'], array_column($papan['pos'][0]['dilayani'], 'no_nup'));
        $this->assertSame(['9002'], array_column($papan['pos'][1]['menunggu'], 'no_nup'));
        $this->assertSame(1, $papan['pos'][0]['selesai']);
        $this->assertSame(2, $papan['ringkasan']['aktif']);

        Livewire::actingAs($u)->test(LiveBoard::class, ['eventId' => $e['event']->id])
            ->assertOk()->assertSee('Lia')->assertSee('Rio')->assertSee('Konsultasi')
            ->set('cari', 'rio')->assertSee('Hasil pencarian (1)');
    }

    public function test_per_participant_view_dot_states(): void
    {
        $this->superUser();
        $this->projectId();
        $this->seed(EventDemoSeeder::class);
        $e = EtEvent::where('name', EventDemoSeeder::NAMA)->sole();

        $j = app(AntreanService::class)->perjalanan($e);
        $this->assertSame(['Check-in', 'Konsultasi', 'Validasi Berkas', 'Pilih Unit', 'Merchandise'], array_column($j['pos'], 'name'));
        $this->assertSame(29, count($j['baris']));   // semua peserta, termasuk yang pulang & yang di-hold
        $baris = collect($j['baris'])->keyBy('no_nup');
        $state = fn (string $nup) => array_column($baris[$nup]['titik'], 'state');

        // Selesai lewat Validasi; Pilih Unit 13 mnt (target 15) tepat.
        $this->assertSame(['tepat', 'tepat', 'tepat', 'tepat', 'tepat'], $state('DEMO-0001'));
        // Selesai, melewati Validasi (opsional).
        $this->assertSame(['tepat', 'tepat', 'dilewati', 'tepat', 'tepat'], $state('DEMO-0029'));
        // Konsultasi 11 mnt (target 10) → telat.
        $this->assertSame('telat', $state('DEMO-0009')[1]);
        // Sedang dilayani di Pilih Unit (radar), Validasi dilewati.
        $this->assertSame(['tepat', 'tepat', 'dilewati', 'dilayani', 'belum'], $state('DEMO-0014'));
        // Menunggu Konsultasi.
        $this->assertSame(['tepat', 'menunggu', 'belum', 'belum', 'belum'], $state('DEMO-0024'));
        $this->assertNotNull($baris['DEMO-0024']['since']);
        $this->assertSame('left', $baris['DEMO-0028']['status']);
        $this->assertNull($baris['DEMO-0028']['since']);

        // Cari ikut menyaring baris.
        $this->assertSame(['DEMO-0003', 'DEMO-0004'], array_column(app(AntreanService::class)->perjalanan($e, 'citra')['baris'], 'no_nup'));

        Livewire::actingAs(User::role('super_admin')->first())
            ->test(LiveBoard::class, ['eventId' => $e->id])
            ->call('gantiTampilan', 'peserta')
            ->assertSet('tampilan', 'peserta')
            ->assertSee('Sedang dilayani')->assertSee('Check-in')->assertSee('Gilang Ramadhan')
            ->call('gantiTampilan', 'lain')
            ->assertSet('tampilan', 'pos');
    }

    public function test_access_super_or_can_live_board_only(): void
    {
        $e = $this->eventOpen($this->foNupEvent());
        $koor = $this->user();
        $scanner = $this->user();
        EtAccess::create(['event_id' => $e['event']->id, 'user_id' => $koor->id, 'can_live_board' => true]);
        $this->grantScan($scanner, $e['event'], $e['m2']);

        $this->actingAs($scanner);
        $this->assertFalse(LiveBoard::canAccess());
        $this->actingAs($koor);
        $this->assertTrue(LiveBoard::canAccess());
        $this->actingAs($this->superUser());
        $this->assertTrue(LiveBoard::canAccess());
    }

    public function test_corrections_require_reason_and_rights(): void
    {
        Carbon::setTestNow('2026-11-20 10:00:00');
        $ev = $this->foNupEvent();
        $this->foNup($ev, $this->foCustomer('C-KR'), '9101');
        $e = $this->eventOpen($ev);
        $u = $this->superUser();
        app(ScanService::class)->handle($e['m2']->id, '9101', $u);
        $p = EtParticipant::sole();
        $v = EtVisit::sole();
        $k = app(KoreksiService::class);

        try {
            $k->tutupKunjungan($v, $u, ' ');
            $this->fail('alasan kosong harus ditolak');
        } catch (RuntimeException $ex) {
            $this->assertSame('Alasan koreksi wajib diisi.', $ex->getMessage());
        }
        try {
            $k->tutupKunjungan($v, $this->user(), 'tanpa hak');
            $this->fail('tanpa hak harus ditolak');
        } catch (RuntimeException $ex) {
            $this->assertSame('Akun tidak punya hak koreksi di event ini.', $ex->getMessage());
        }

        Carbon::setTestNow('2026-11-20 10:12:00');
        $k->tutupKunjungan($v, $u, 'HP keluar mati');
        $v->refresh();
        $this->assertSame(EtVisit::END_KOREKSI, $v->end_reason);
        $this->assertTrue($v->hasFlag('koreksi'));
        $this->assertSame($e['s3']->id, $p->fresh()->current_station_id);

        $k->mulaiLayanan($p, $e['s3'], $u, 'lupa scan masuk');
        $this->assertSame(EtParticipant::IN_SERVICE, $p->fresh()->current_state);

        $k->tandaiPulang($p, $u, 'customer pulang');
        $this->assertSame(EtParticipant::LEFT, $p->fresh()->status);
        $this->assertSame(0, EtVisit::whereNull('ended_at')->count());

        $k->bukaKembali($p, $u, 'kembali lagi');
        $p->refresh();
        $this->assertSame(EtParticipant::ACTIVE, $p->status);
        $this->assertSame(EtParticipant::WAITING, $p->current_state);

        $this->assertSame(4, EtScanLog::where('raw_payload', 'koreksi')->whereNull('scan_point_id')->count());
        $this->assertStringContainsString('HP keluar mati', EtScanLog::where('raw_payload', 'koreksi')->orderBy('id')->first()->reason);
    }

    public function test_demo_seeder_builds_running_event(): void
    {
        $this->superUser();
        $this->projectId();   // DB test belum punya proyek
        $this->seed(EventDemoSeeder::class);

        $e = EtEvent::where('name', EventDemoSeeder::NAMA)->sole();
        $this->assertSame(EtEvent::OPEN, $e->status);
        $this->assertSame(29, $e->participants()->count());   // 28 aktivasi + 1 aktivasi tapi di-hold

        $papan = app(AntreanService::class)->papan($e);
        $r = $papan['ringkasan'];
        $this->assertSame(8, $r['selesai']);
        $this->assertSame(1, $r['pulang']);
        $pos = collect($papan['pos'])->keyBy('code');
        $this->assertCount(4, $pos['KONS']['dilayani']);
        $this->assertCount(8, $pos['KONS']['menunggu']);
        $this->assertCount(2, $pos['VAL']['dilayani']);
        $this->assertCount(3, $pos['PU']['dilayani']);
        $this->assertCount(2, $pos['PU']['menunggu']);
        // Keluar Konsultasi tanpa ke Validasi → menunggu di pos berikutnya (Validasi, opsional).
        $this->assertSame(['DEMO-0017'], array_column($pos['VAL']['menunggu'], 'no_nup'));

        // Dijalankan ulang: tetap satu event demo.
        $this->seed(EventDemoSeeder::class);
        $this->assertSame(1, EtEvent::where('name', EventDemoSeeder::NAMA)->count());
    }
}
