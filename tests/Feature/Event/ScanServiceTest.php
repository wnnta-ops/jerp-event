<?php

namespace Tests\Feature\Event;

use App\Modules\Event\Data\ScanResult;
use App\Modules\Event\Models\EtEvent;
use App\Modules\Event\Models\EtParticipant;
use App\Modules\Event\Models\EtScanLog;
use App\Modules\Event\Models\EtVisit;
use App\Modules\Event\Services\ScanService;
use Illuminate\Support\Carbon;

class ScanServiceTest extends EventTestCase
{
    private function scan(int $pointId, string $payload, $user, ?int $pick = null): ScanResult
    {
        return app(ScanService::class)->handle($pointId, $payload, $user, $pick);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_masuk_keluar_timing_and_waiting_time(): void
    {
        Carbon::setTestNow('2026-11-20 10:00:00');
        $ev = $this->foNupEvent();
        $this->foNup($ev, $this->foCustomer('C-T1'), '0001', ['tgl_check_in' => '2026-11-20 09:50:00']);
        $e = $this->eventOpen($ev);
        $u = $this->superUser();

        $r = $this->scan($e['m2']->id, 'CUST:C-T1', $u);
        $this->assertSame(ScanResult::ACCEPTED, $r->status, $r->message);
        $this->assertSame('0001', $r->no_nup);

        Carbon::setTestNow('2026-11-20 10:07:30');
        $this->assertTrue($this->scan($e['k2']->id, 'CUST:C-T1', $u)->ok());

        $v = EtVisit::where('station_id', $e['s2']->id)->sole();
        $this->assertSame(450, $v->serviceSeconds());                       // 7m30s layanan
        $p = EtParticipant::sole();
        $this->assertSame(600, (int) $p->checked_in_at->diffInSeconds($v->started_at)); // tunggu pos 2 = 10 menit sejak check-in FO
        $this->assertSame($e['s3']->id, $p->current_station_id);
        $this->assertSame(EtParticipant::WAITING, $p->current_state);
        $this->assertSame(EtVisit::END_SCAN_KELUAR, $v->end_reason);
    }

    public function test_masuk_at_next_pos_auto_closes_open_visit(): void
    {
        Carbon::setTestNow('2026-11-20 10:00:00');
        $ev = $this->foNupEvent();
        $this->foNup($ev, $this->foCustomer('C-AC'), '0002');
        $e = $this->eventOpen($ev);
        $u = $this->superUser();

        $this->scan($e['m2']->id, '0002', $u);
        Carbon::setTestNow('2026-11-20 10:20:00');
        $r = $this->scan($e['m3']->id, '0002', $u);
        $this->assertTrue($r->ok(), $r->message);

        $v2 = EtVisit::where('station_id', $e['s2']->id)->sole();
        $this->assertSame(EtVisit::END_NEXT_SCAN, $v2->end_reason);
        $this->assertTrue($v2->hasFlag('estimated'));
        $this->assertSame('2026-11-20 10:20:00', $v2->ended_at->toDateTimeString());
        $this->assertNull(EtVisit::where('station_id', $e['s3']->id)->sole()->ended_at);
    }

    public function test_keluar_without_masuk_uses_previous_end(): void
    {
        Carbon::setTestNow('2026-11-20 10:00:00');
        $ev = $this->foNupEvent();
        $this->foNup($ev, $this->foCustomer('C-TM'), '0003', ['tgl_check_in' => '2026-11-20 09:40:00']);
        $e = $this->eventOpen($ev);
        $u = $this->superUser();

        // Pos 2 tanpa masuk → mulai = tgl_check_in FO.
        $r = $this->scan($e['k2']->id, '0003', $u);
        $this->assertTrue($r->ok(), $r->message);
        $this->assertNotNull($r->warning);
        $v2 = EtVisit::where('station_id', $e['s2']->id)->sole();
        $this->assertTrue($v2->hasFlag('tanpa_masuk'));
        $this->assertSame('2026-11-20 09:40:00', $v2->started_at->toDateTimeString());

        // Pos 3 tanpa masuk → mulai = keluar pos 2; pos finish → selesai.
        Carbon::setTestNow('2026-11-20 10:30:00');
        $this->assertTrue($this->scan($e['k3']->id, '0003', $u)->ok());
        $v3 = EtVisit::where('station_id', $e['s3']->id)->sole();
        $this->assertSame('2026-11-20 10:00:00', $v3->started_at->toDateTimeString());

        $p = EtParticipant::sole();
        $this->assertSame(EtParticipant::FINISHED, $p->status);
        $this->assertSame('2026-11-20 10:30:00', $p->finished_at->toDateTimeString());

        // Sudah selesai → ditolak.
        Carbon::setTestNow('2026-11-20 10:31:00');
        $r = $this->scan($e['m2']->id, '0003', $u);
        $this->assertSame(ScanResult::REJECTED, $r->status);
        $this->assertSame('Sudah selesai', $r->message);
    }

    public function test_duplicate_window(): void
    {
        Carbon::setTestNow('2026-11-20 10:00:00');
        $ev = $this->foNupEvent();
        $this->foNup($ev, $this->foCustomer('C-DUP'), '0004');
        $e = $this->eventOpen($ev);
        $u = $this->superUser();

        $this->assertTrue($this->scan($e['m2']->id, '0004', $u)->ok());
        Carbon::setTestNow('2026-11-20 10:00:03');
        $this->assertSame(ScanResult::DUPLICATE, $this->scan($e['m2']->id, '0004', $u)->status);
        $this->assertSame(1, EtVisit::count());
        $this->assertSame(1, EtScanLog::where('result', 'duplicate')->count());

        // Lewat jendela: masuk lagi di pos yang sama tetap tak membuat kunjungan kedua.
        Carbon::setTestNow('2026-11-20 10:00:10');
        $this->assertSame(ScanResult::DUPLICATE, $this->scan($e['m2']->id, '0004', $u)->status);
        $this->assertSame(1, EtVisit::count());
    }

    public function test_access_denied_and_event_closed(): void
    {
        $ev = $this->foNupEvent();
        $this->foNup($ev, $this->foCustomer('C-ACC'), '0005');
        $e = $this->eventOpen($ev);

        $scanner = $this->user();
        $this->grantScan($scanner, $e['event'], $e['m2']);

        $r = $this->scan($e['k2']->id, '0005', $scanner);   // titik yang tidak ditugaskan
        $this->assertSame('Akun tidak punya akses ke pos ini', $r->message);
        $this->assertTrue($this->scan($e['m2']->id, '0005', $scanner)->ok());

        $e['event']->update(['status' => EtEvent::CLOSED]);
        $r = $this->scan($e['m2']->id, '0005', $scanner);
        $this->assertSame(ScanResult::REJECTED, $r->status);
        $this->assertSame('Event sudah ditutup', $r->message);

        $draft = $this->eventOpen($this->foNupEvent('D'), EtEvent::DRAFT);
        $r = $this->scan($draft['m2']->id, '0005', $this->superUser());
        $this->assertSame('Event belum dibuka', $r->message);

        // Setiap percobaan tercatat.
        $this->assertSame(4, EtScanLog::count());
    }

    public function test_super_roles_from_config_pass_everything(): void
    {
        $ev = $this->foNupEvent();
        $this->foNup($ev, $this->foCustomer('C-SUP'), '0006');
        $e = $this->eventOpen($ev);

        foreach (config('akses.peran_super') as $i => $peran) {
            $u = $this->superUser($peran);
            $point = [$e['m2'], $e['k2']][$i % 2];
            $this->assertTrue($this->scan($point->id, '0006', $u)->ok(), $peran);
        }
    }

    public function test_rejection_messages(): void
    {
        $ev = $this->foNupEvent();
        $this->foNup($ev, $this->foCustomer('C-H'), '0007', ['is_hold' => true]);
        $e = $this->eventOpen($ev);
        $u = $this->superUser();

        $this->assertSame('NUP sedang di-hold', $this->scan($e['m2']->id, '0007', $u)->message);
        $this->assertSame('Kode tidak ditemukan', $this->scan($e['m2']->id, 'CUST:ZZZ', $u)->message);
        $this->assertSame('QR tidak dikenali', $this->scan($e['m2']->id, '   ', $u)->message);
    }

    public function test_undo_last_scan(): void
    {
        Carbon::setTestNow('2026-11-20 10:00:00');
        $ev = $this->foNupEvent();
        $this->foNup($ev, $this->foCustomer('C-UN'), '0008');
        $e = $this->eventOpen($ev);
        $u = $this->superUser();

        $this->scan($e['m2']->id, '0008', $u);
        Carbon::setTestNow('2026-11-20 10:05:00');
        $r = $this->scan($e['k2']->id, '0008', $u);

        Carbon::setTestNow('2026-11-20 10:05:20');
        $undo = app(ScanService::class)->undo($r->log_id, $u);
        $this->assertTrue($undo->ok(), $undo->message);

        $v = EtVisit::sole();
        $this->assertNull($v->ended_at);
        $p = EtParticipant::sole();
        $this->assertSame(EtParticipant::IN_SERVICE, $p->current_state);
        $this->assertSame($e['s2']->id, $p->current_station_id);
        $this->assertSame(1, EtScanLog::where('result', 'undone')->count());

        // Dua kali tidak bisa; lewat 30 detik tidak bisa.
        $this->assertFalse(app(ScanService::class)->undo($r->log_id, $u)->ok());
    }
}
