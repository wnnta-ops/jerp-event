<?php

namespace Tests\Feature\Event;

use App\Modules\Event\Models\EtEvent;
use App\Modules\Event\Models\EtParticipant;
use App\Modules\Event\Models\EtScanPoint;
use App\Modules\Event\Models\EtVisit;
use App\Modules\Event\Services\EventSetupService;
use App\Modules\Event\Services\ScanService;
use Illuminate\Support\Carbon;

/** Event mandiri: tanpa event NUP FO, peserta per customer, tercatat saat scan pertama. */
class EventMandiriTest extends EventTestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function buat(): EtEvent
    {
        $svc = app(EventSetupService::class);
        $pos = [['code' => 'DTG', 'name' => 'Kedatangan'], ['code' => 'KONS', 'name' => 'Konsultasi']];

        return $svc->create([
            'jenis' => EventSetupService::JENIS_MANDIRI, 'name' => 'Open House', 'event_date' => '2026-11-20',
            'pos' => $pos, 'titik' => $svc->titikBawaan($pos), 'buka_sekarang' => true,
        ], $this->superUser());
    }

    private function titik(EtEvent $e, string $code, string $kind): EtScanPoint
    {
        return EtScanPoint::whereHas('station', fn ($q) => $q->where('event_id', $e->id)->where('code', $code))->where('kind', $kind)->sole();
    }

    public function test_setup_without_nup_event_has_no_fo_station(): void
    {
        $e = $this->buat();
        $this->assertNull($e->fo_nup_event_id);
        $this->assertSame(EtEvent::OPEN, $e->status);
        $this->assertSame(['DTG', 'KONS'], $e->stations()->pluck('code')->all());
        $this->assertSame([1, 2], $e->stations()->pluck('sequence')->all());
        $this->assertSame(EventSetupService::JENIS_MANDIRI, app(EventSetupService::class)->formData($e)['jenis']);
    }

    public function test_scan_creates_customer_participant_and_tracks_journey(): void
    {
        Carbon::setTestNow('2026-11-20 10:00:00');
        $c = $this->foCustomer('C-MANDIRI', 'Gita');
        $this->foNup($this->foNupEvent(), $c, '7001', ['verif_status' => 'PENDING', 'tgl_check_in' => null, 'status' => 'DAFTAR']);
        $e = $this->buat();
        $u = $this->superUser();
        $scan = app(ScanService::class);

        $r = $scan->handle($this->titik($e, 'DTG', 'masuk')->id, 'CUST:C-MANDIRI', $u);
        $this->assertTrue($r->ok(), $r->message);
        $this->assertSame('Gita', $r->name);
        $p = EtParticipant::where('event_id', $e->id)->sole();
        $this->assertSame($c, $p->fo_customer_id);
        $this->assertNull($p->fo_nup_registration_id);
        $this->assertSame('2026-11-20 10:00:00', $p->checked_in_at->toDateTimeString());

        // Duplikat per peserta tetap jalan tanpa NUP.
        Carbon::setTestNow('2026-11-20 10:00:02');
        $this->assertSame('duplicate', $scan->handle($this->titik($e, 'DTG', 'masuk')->id, 'CUST:C-MANDIRI', $u)->status);

        // No. NUP yang diketik dikenali sebagai customer-nya (status NUP tak dipersoalkan).
        Carbon::setTestNow('2026-11-20 10:05:00');
        $this->assertTrue($scan->handle($this->titik($e, 'DTG', 'keluar')->id, '7001', $u)->ok());
        Carbon::setTestNow('2026-11-20 10:20:00');
        $r = $scan->handle($this->titik($e, 'KONS', 'keluar')->id, 'C-MANDIRI', $u);
        $this->assertTrue($r->ok(), $r->message);

        $this->assertSame(1, EtParticipant::where('event_id', $e->id)->count());
        $p->refresh();
        $this->assertSame(EtParticipant::FINISHED, $p->status);
        $this->assertSame(300, EtVisit::where('participant_id', $p->id)->orderBy('id')->first()->serviceSeconds());

        // Undo tetap bisa (dikunci per peserta).
        $this->assertTrue($scan->undo($r->log_id, $u)->ok());
        $this->assertSame(EtParticipant::ACTIVE, $p->fresh()->status);

        $this->assertSame('Kode tidak ditemukan', $scan->handle($this->titik($e, 'DTG', 'masuk')->id, 'CUST:TIDAK-ADA', $u)->message);
    }

    public function test_sync_skips_standalone_events(): void
    {
        $e = $this->buat();
        $this->artisan('event:sinkron')->assertSuccessful();
        $this->assertSame(0, EtParticipant::where('event_id', $e->id)->count());
    }
}
