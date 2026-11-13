<?php

namespace Tests\Feature\Event;

use App\Modules\Event\Models\EtParticipant;
use App\Modules\Event\Services\SyncCheckInService;
use Illuminate\Support\Facades\DB;

class SyncCheckInTest extends EventTestCase
{
    public function test_creates_participants_from_tgl_check_in_and_handles_cancel(): void
    {
        $ev = $this->foNupEvent();
        $c = $this->foCustomer('C-SYNC', 'Rudi');
        $a = $this->foNup($ev, $c, '0401', ['tgl_check_in' => '2026-11-20 09:15:00']);
        $b = $this->foNup($ev, $c, '0402');
        $this->foNup($ev, $this->foCustomer('C-BLM2'), '0403', ['status' => 'DAFTAR', 'tgl_check_in' => null]);
        $this->foNup($this->foNupEvent('LAIN'), $this->foCustomer('C-LAIN'), '0404');
        $e = $this->eventOpen($ev);

        $hasil = app(SyncCheckInService::class)->syncEvent($e['event']);
        $this->assertSame(2, $hasil['baru']);

        $pa = EtParticipant::where('fo_nup_registration_id', $a)->sole();
        $this->assertSame('2026-11-20 09:15:00', $pa->checked_in_at->toDateTimeString());
        $this->assertSame('Rudi', $pa->name_snapshot);
        $this->assertSame('C-SYNC', $pa->customer_code);
        $this->assertSame($e['s2']->id, $pa->current_station_id);
        $this->assertSame(EtParticipant::WAITING, $pa->current_state);
        $this->assertNotNull($e['event']->fresh()->last_synced_at);

        // Idempoten.
        $this->assertSame(0, app(SyncCheckInService::class)->syncEvent($e['event']->fresh())['baru']);
        $this->assertSame(2, EtParticipant::count());

        // Aktivasi 0402 dibatalkan di FO → peserta cancelled.
        DB::table('fo_nup_registrations')->where('id', $b)->update(['status' => 'DAFTAR', 'tgl_check_in' => null]);
        $hasil = app(SyncCheckInService::class)->syncEvent($e['event']->fresh());
        $this->assertSame(1, $hasil['dibatalkan']);
        $this->assertSame(EtParticipant::CANCELLED, EtParticipant::where('fo_nup_registration_id', $b)->sole()->status);

        // Diaktivasi ulang → aktif lagi.
        DB::table('fo_nup_registrations')->where('id', $b)->update(['status' => 'CHECK_IN', 'tgl_check_in' => now()]);
        app(SyncCheckInService::class)->syncEvent($e['event']->fresh());
        $this->assertSame(EtParticipant::ACTIVE, EtParticipant::where('fo_nup_registration_id', $b)->sole()->status);
    }
}
