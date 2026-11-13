<?php

namespace Tests\Feature\Event;

use App\Modules\Event\Services\ScanService;
use App\Modules\Event\Services\SyncCheckInService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * FO read-only: satu alur penuh (sinkron, multi-NUP, masuk/keluar, selesai,
 * undo, tolak) tidak boleh mengubah satu baris pun di tabel fo_*.
 */
class FoUntouchedTest extends EventTestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array<string, string> tabel → sidik (jumlah baris + max updated_at + hash isi) */
    private function snapshot(): array
    {
        $tabel = collect(DB::select("select table_name as t from information_schema.tables where table_schema = database() and table_name like 'fo\\_%'"))
            ->pluck('t')->sort()->values();

        return $tabel->mapWithKeys(function (string $t) {
            $q = DB::table($t);
            $hash = md5(json_encode($q->orderBy(DB::raw('1'))->get()));

            return [$t => $q->count().'|'.$hash];
        })->all();
    }

    public function test_full_scan_flow_does_not_change_fo_rows(): void
    {
        Carbon::setTestNow('2026-11-20 10:00:00');
        $ev = $this->foNupEvent();
        $c = $this->foCustomer('C-RO');
        $this->foNup($ev, $c, '0501');
        $this->foNup($ev, $c, '0502');
        $this->foNup($ev, $this->foCustomer('C-RO-H'), '0503', ['is_hold' => true]);
        $e = $this->eventOpen($ev);
        $u = $this->superUser();

        $sebelum = $this->snapshot();

        app(SyncCheckInService::class)->syncEvent($e['event']);
        $s = app(ScanService::class);
        $s->handle($e['m2']->id, 'CUST:C-RO', $u);          // picker
        $s->handle($e['m2']->id, '0501', $u);
        Carbon::setTestNow('2026-11-20 10:05:00');
        $s->handle($e['k2']->id, '0501', $u);
        $s->handle($e['m3']->id, '0501', $u);
        Carbon::setTestNow('2026-11-20 10:10:00');
        $r = $s->handle($e['k3']->id, '0501', $u);
        $s->undo($r->log_id, $u);
        $s->handle($e['k2']->id, '0502', $u);
        $s->handle($e['m2']->id, '0503', $u);               // hold → ditolak
        $s->handle($e['m2']->id, 'CUST:TIDAK-ADA', $u);

        $this->assertSame($sebelum, $this->snapshot());
    }
}
