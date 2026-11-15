<?php

namespace Tests\Feature\Event;

use App\Modules\Event\Models\EtEvent;
use App\Modules\Event\Models\EtScanPoint;
use App\Modules\Event\Models\EtStation;
use App\Modules\Event\Models\EtVisit;
use App\Modules\Event\Services\AntreanService;
use App\Modules\Event\Services\EventSetupService;
use App\Modules\Event\Services\ScanService;
use RuntimeException;

/** Pos opsional: boleh dilewati tanpa dianggap salah urutan. */
class PosOpsionalTest extends EventTestCase
{
    private function buat(int $nupEventId): EtEvent
    {
        $svc = app(EventSetupService::class);
        $pos = [
            ['code' => 'KONS', 'name' => 'Konsultasi'],
            ['code' => 'KPR', 'name' => 'Simulasi KPR', 'is_optional' => true],
            ['code' => 'FOTO', 'name' => 'Foto', 'is_optional' => true],
            ['code' => 'PU', 'name' => 'Pilih Unit'],
        ];

        return $svc->create([
            'name' => 'Pilih Unit', 'event_date' => '2026-11-20', 'fo_nup_event_id' => $nupEventId,
            'pos' => $pos, 'titik' => $svc->titikBawaan($pos), 'buka_sekarang' => true,
        ], $this->superUser());
    }

    private function titik(EtEvent $e, string $code, string $kind): EtScanPoint
    {
        return EtScanPoint::whereHas('station', fn ($q) => $q->where('event_id', $e->id)->where('code', $code))->where('kind', $kind)->sole();
    }

    public function test_setup_stores_optional_and_rejects_optional_last_pos(): void
    {
        $ev = $this->foNupEvent();
        $e = $this->buat($ev);
        $this->assertSame(['FO' => false, 'KONS' => false, 'KPR' => true, 'FOTO' => true, 'PU' => false],
            $e->stations()->get()->mapWithKeys(fn (EtStation $s) => [$s->code => $s->is_optional])->all());
        $this->assertTrue(collect(app(EventSetupService::class)->formData($e)['pos'])->firstWhere('code', 'KPR')['is_optional']);

        $svc = app(EventSetupService::class);
        $pos = [['code' => 'A', 'name' => 'A'], ['code' => 'B', 'name' => 'B', 'is_optional' => true]];
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Pos terakhir (Selesai) tidak boleh opsional.');
        $svc->create(['name' => 'x', 'event_date' => '2026-11-20', 'fo_nup_event_id' => $ev, 'pos' => $pos, 'titik' => $svc->titikBawaan($pos)], $this->superUser());
    }

    public function test_skipping_optional_pos_has_no_warning_but_skipping_mandatory_does(): void
    {
        $ev = $this->foNupEvent();
        $this->foNup($ev, $this->foCustomer('C-OP1'), '8001');
        $this->foNup($ev, $this->foCustomer('C-OP2'), '8002');
        $e = $this->buat($ev);
        $u = $this->superUser();
        $scan = app(ScanService::class);

        // 8001: Konsultasi → langsung Pilih Unit (lewati 2 pos opsional) → tanpa peringatan.
        $scan->handle($this->titik($e, 'KONS', 'masuk')->id, '8001', $u);
        $scan->handle($this->titik($e, 'KONS', 'keluar')->id, '8001', $u);
        $r = $scan->handle($this->titik($e, 'PU', 'masuk')->id, '8001', $u);
        $this->assertTrue($r->ok(), $r->message);
        $this->assertNull($r->warning);

        // 8002: langsung Foto, melompati Konsultasi yang WAJIB → peringatan.
        $r = $scan->handle($this->titik($e, 'FOTO', 'masuk')->id, '8002', $u);
        $this->assertTrue($r->ok());
        $this->assertSame('Urutan pos tidak sesuai — seharusnya ke Konsultasi', $r->warning);
        $this->assertTrue(EtVisit::whereHas('station', fn ($q) => $q->where('code', 'FOTO'))->sole()->hasFlag('tidak_urut'));
    }

    public function test_next_mandatory_pos_lists_people_waiting_at_optional_pos(): void
    {
        $ev = $this->foNupEvent();
        $this->foNup($ev, $this->foCustomer('C-OP3'), '8003');
        $e = $this->buat($ev);
        $u = $this->superUser();
        $scan = app(ScanService::class);

        $scan->handle($this->titik($e, 'KONS', 'keluar')->id, '8003', $u);   // sekarang menunggu di KPR (opsional)

        $antrean = app(AntreanService::class);
        $st = fn (string $c) => $e->stations()->where('code', $c)->sole();
        $this->assertSame(['8003'], array_column($antrean->menunggu($st('KPR')), 'no_nup'));
        $this->assertSame(['8003'], array_column($antrean->menunggu($st('FOTO')), 'no_nup'));
        $this->assertSame(['8003'], array_column($antrean->menunggu($st('PU')), 'no_nup'));
        $this->assertSame([], array_column($antrean->menunggu($st('KONS')), 'no_nup'));
    }
}
