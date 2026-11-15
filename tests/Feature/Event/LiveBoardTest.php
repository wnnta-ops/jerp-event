<?php

namespace Tests\Feature\Event;

use App\Filament\Event\Pages\LiveBoard;
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
