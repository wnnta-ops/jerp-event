<?php

namespace Tests\Feature\Event;

use App\Livewire\Event\Scanner;
use App\Modules\Event\Models\EtAccess;
use App\Modules\Event\Models\EtEvent;
use App\Modules\Event\Models\EtParticipant;
use App\Modules\Event\Models\EtScanLog;
use App\Modules\Event\Models\EtVisit;
use App\Modules\Event\Services\AntreanService;
use App\Modules\Event\Services\ScanService;
use App\Modules\Event\Services\SyncCheckInService;
use App\Support\TenantContext;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

class ScannerTest extends EventTestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_requires_login(): void
    {
        $this->get('/event/scan')->assertRedirect(route('login'));
    }

    public function test_event_panel_links_to_scanner_for_super_and_scan_accounts_only(): void
    {
        $e = $this->eventOpen($this->foNupEvent());
        $scanner = $this->user();
        $this->grantScan($scanner, $e['event'], $e['m2']);
        $koorTanpaScan = $this->user();
        EtAccess::create(['event_id' => $e['event']->id, 'user_id' => $koorTanpaScan->id, 'can_live_board' => true]);

        $this->actingAs($this->superUser())->get('/event/events')->assertOk()->assertSee(route('event.scan'));
        $this->flushSession();
        $this->actingAs($scanner)->get('/event/events')->assertOk()->assertSee(route('event.scan'));
        $this->flushSession();
        $this->actingAs($koorTanpaScan)->get('/event/events')->assertOk()->assertDontSee(route('event.scan'));
    }

    public function test_scanner_with_one_point_opens_straight_on_it_and_scans(): void
    {
        $ev = $this->foNupEvent();
        $c = $this->foCustomer('C-SC', 'Andi');
        $this->foNup($ev, $c, '0901');
        $e = $this->eventOpen($ev);
        $this->eventOpen($this->foNupEvent('LAIN'));   // event lain: tak terlihat
        $scanner = $this->user();
        $this->grantScan($scanner, $e['event'], $e['m2']);

        $this->actingAs($scanner)->get('/event/scan')->assertOk()->assertSee('Konsultasi · MASUK');

        $lw = Livewire::actingAs($scanner)->test(Scanner::class)
            ->assertSet('eventId', $e['event']->id)
            ->assertSet('scanPointId', $e['m2']->id);

        $res = $lw->instance()->scan('CUST:C-SC');
        $this->assertSame('accepted', $res['status']);
        $this->assertSame('Andi', $res['name']);
        $this->assertSame('0901', $res['no_nup']);
        $this->assertNotNull($res['log_id']);
    }

    public function test_draft_events_listed_as_belum_aktif_and_not_selectable(): void
    {
        $open = $this->eventOpen($this->foNupEvent('A'));
        $draft = $this->eventOpen($this->foNupEvent('B'), EtEvent::DRAFT);
        $draft['event']->update(['name' => 'Event Draft Uji']);
        $open2 = $this->eventOpen($this->foNupEvent('C'));
        $u = $this->superUser();

        $lw = Livewire::actingAs($u)->test(Scanner::class)
            ->assertSet('eventId', null)
            ->assertSee('Event Draft Uji')
            ->assertSee('belum aktif')
            ->call('pilihEvent', $draft['event']->id)
            ->assertSet('eventId', null);

        $lw->call('pilihEvent', $open['event']->id)->assertSet('eventId', $open['event']->id);
    }

    public function test_scanner_cannot_select_unassigned_point(): void
    {
        $e = $this->eventOpen($this->foNupEvent());
        $scanner = $this->user();
        $this->grantScan($scanner, $e['event'], $e['m2']);
        $this->grantScan($scanner, $e['event'], $e['k3']);

        $lw = Livewire::actingAs($scanner)->test(Scanner::class)->assertSet('scanPointId', null);
        $this->assertFalse($lw->instance()->pilihTitik($e['k2']->id));
        $this->assertTrue($lw->instance()->pilihTitik($e['k3']->id));

        // Event ditutup → titik tak bisa dipilih lagi.
        $e['event']->update(['status' => EtEvent::CLOSED]);
        $this->assertFalse(Livewire::actingAs($scanner)->test(Scanner::class)->instance()->pilihTitik($e['m2']->id));
    }

    public function test_masuk_list_waiting_and_keluar_list_in_service_with_manual_selesai(): void
    {
        Carbon::setTestNow('2026-11-20 10:00:00');
        $ev = $this->foNupEvent();
        $this->foNup($ev, $this->foCustomer('C-L1', 'Satu'), '1101', ['tgl_check_in' => '2026-11-20 09:30:00']);
        $this->foNup($ev, $this->foCustomer('C-L2', 'Dua'), '1102', ['tgl_check_in' => '2026-11-20 09:45:00']);
        $e = $this->eventOpen($ev);
        $u = $this->superUser();
        app(SyncCheckInService::class)->syncEvent($e['event']);

        $antrean = app(AntreanService::class);
        $this->assertSame(['1101', '1102'], collect($antrean->menunggu($e['s2']))->pluck('no_nup')->all());

        app(ScanService::class)->handle($e['m2']->id, '1102', $u);
        $this->assertSame(['1101'], collect($antrean->menunggu($e['s2']))->pluck('no_nup')->all());
        $dilayani = collect($antrean->dilayani($e['s2']));
        $this->assertSame(['1102'], $dilayani->pluck('no_nup')->all());

        Livewire::actingAs($u)->test(Scanner::class)
            ->call('pilihEvent', $e['event']->id)
            ->call('pilihTitik', $e['k2']->id)
            ->assertSee('Sedang dilayani (1)')
            ->assertSee('Dua');

        // Tombol Selesai.
        Carbon::setTestNow('2026-11-20 10:04:00');
        $p = EtParticipant::where('no_nup', '1102')->sole();
        $res = app(ScanService::class)->selesaikanManual($e['k2']->id, $p->id, $u);
        $this->assertSame('accepted', $res->status, $res->message);
        $v = EtVisit::where('participant_id', $p->id)->sole();
        $this->assertSame(EtVisit::END_MANUAL, $v->end_reason);
        $this->assertSame(240, $v->serviceSeconds());
        $this->assertStringStartsWith('Selesai manual', EtScanLog::find($res->log_id)->reason);
        $this->assertTrue(collect($antrean->dilayani($e['s2']))->isEmpty());
        $this->assertSame(['1102'], collect($antrean->menunggu($e['s3']))->pluck('no_nup')->all());

        // Tak sedang dilayani → ditolak; titik Masuk tak boleh menekan Selesai.
        $this->assertSame('Tidak sedang dilayani di pos ini', app(ScanService::class)->selesaikanManual($e['k2']->id, $p->id, $u)->message);
        $this->assertSame('Akun tidak punya akses ke pos ini', app(ScanService::class)->selesaikanManual($e['m2']->id, $p->id, $u)->message);

        // Selesai manual bisa dibatalkan seperti scan biasa.
        $this->assertTrue(app(ScanService::class)->undo($res->log_id, $u)->ok());
        $this->assertNull($v->fresh()->ended_at);
    }

    public function test_sync_job_command_handles_all_open_events_with_tenant_context(): void
    {
        $ev = $this->foNupEvent();
        $this->foNup($ev, $this->foCustomer('C-JOB'), '1201');
        $open = $this->eventOpen($ev);
        $draft = $this->eventOpen($ev, EtEvent::DRAFT);

        $this->artisan('event:sinkron')->assertSuccessful();

        $this->assertSame(1, EtParticipant::where('event_id', $open['event']->id)->count());
        $this->assertSame(0, EtParticipant::where('event_id', $draft['event']->id)->count());
        $this->assertNotNull($open['event']->fresh()->last_synced_at);
        $this->assertSame(1, app(TenantContext::class)->id());
    }
}
