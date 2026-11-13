<?php

namespace Tests\Feature\Event;

use App\Modules\Event\Data\ScanResult;
use App\Modules\Event\Models\EtParticipant;
use App\Modules\Event\Models\EtVisit;
use App\Modules\Event\Services\ScanService;
use Illuminate\Support\Carbon;

class MultiNupTest extends EventTestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function scan(int $pointId, string $payload, $user, ?int $pick = null): ScanResult
    {
        return app(ScanService::class)->handle($pointId, $payload, $user, $pick);
    }

    public function test_picker_when_two_nups_then_each_keeps_own_journey(): void
    {
        Carbon::setTestNow('2026-11-20 10:00:00');
        $ev = $this->foNupEvent();
        $c = $this->foCustomer('C-2NUP', 'Dewi');
        $a = $this->foNup($ev, $c, '0123');
        $b = $this->foNup($ev, $c, '0124');
        $e = $this->eventOpen($ev);
        $u = $this->superUser();

        // Dua NUP siap masuk → picker.
        $r = $this->scan($e['m2']->id, 'CUST:C-2NUP', $u);
        $this->assertSame(ScanResult::PICK, $r->status);
        $this->assertSame(['0123', '0124'], array_column($r->candidates, 'no_nup'));
        $this->assertStringContainsString('NUP 0123 / NUP 0124', $r->message);
        $this->assertSame(0, EtVisit::count());

        // Pilih 0123.
        $r = $this->scan($e['m2']->id, 'CUST:C-2NUP', $u, $a);
        $this->assertTrue($r->ok());
        $this->assertSame('0123', $r->no_nup);

        // Masuk lagi: 0123 sedang dilayani → otomatis 0124.
        Carbon::setTestNow('2026-11-20 10:02:00');
        $r = $this->scan($e['m2']->id, 'CUST:C-2NUP', $u);
        $this->assertTrue($r->ok(), $r->message);
        $this->assertSame('0124', $r->no_nup);

        // Keluar: dua-duanya terbuka di pos ini → picker.
        Carbon::setTestNow('2026-11-20 10:05:00');
        $this->assertSame(ScanResult::PICK, $this->scan($e['k2']->id, 'CUST:C-2NUP', $u)->status);

        // No. NUP diketik → tanpa picker.
        $this->assertTrue($this->scan($e['k2']->id, '0124', $u)->ok());

        // Keluar lagi: tinggal satu yang terbuka (0123) → otomatis.
        Carbon::setTestNow('2026-11-20 10:09:00');
        $r = $this->scan($e['k2']->id, 'CUST:C-2NUP', $u);
        $this->assertTrue($r->ok(), $r->message);
        $this->assertSame('0123', $r->no_nup);

        // Timer masing-masing.
        $pa = EtParticipant::where('fo_nup_registration_id', $a)->sole();
        $pb = EtParticipant::where('fo_nup_registration_id', $b)->sole();
        $this->assertSame(540, EtVisit::where('participant_id', $pa->id)->sole()->serviceSeconds());
        $this->assertSame(180, EtVisit::where('participant_id', $pb->id)->sole()->serviceSeconds());
    }

    public function test_masuk_auto_picks_when_other_nup_finished(): void
    {
        Carbon::setTestNow('2026-11-20 10:00:00');
        $ev = $this->foNupEvent();
        $c = $this->foCustomer('C-FIN');
        $a = $this->foNup($ev, $c, '0200');
        $b = $this->foNup($ev, $c, '0201');
        $e = $this->eventOpen($ev);
        $u = $this->superUser();

        // 0200 menyelesaikan seluruh pos.
        $this->scan($e['k2']->id, '0200', $u);
        Carbon::setTestNow('2026-11-20 10:10:00');
        $this->scan($e['k3']->id, '0200', $u);
        $this->assertSame(EtParticipant::FINISHED, EtParticipant::where('fo_nup_registration_id', $a)->sole()->status);

        $r = $this->scan($e['m2']->id, 'CUST:C-FIN', $u);
        $this->assertTrue($r->ok(), $r->message);
        $this->assertSame('0201', $r->no_nup);
        $this->assertSame(EtParticipant::IN_SERVICE, EtParticipant::where('fo_nup_registration_id', $b)->sole()->current_state);
    }

    public function test_keluar_auto_picks_the_only_open_visit(): void
    {
        $ev = $this->foNupEvent();
        $c = $this->foCustomer('C-KO');
        $this->foNup($ev, $c, '0300');
        $this->foNup($ev, $c, '0301');
        $e = $this->eventOpen($ev);
        $u = $this->superUser();

        $this->scan($e['m2']->id, '0301', $u);
        $r = $this->scan($e['k2']->id, 'CUST:C-KO', $u);
        $this->assertTrue($r->ok(), $r->message);
        $this->assertSame('0301', $r->no_nup);
    }
}
