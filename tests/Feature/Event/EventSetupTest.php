<?php

namespace Tests\Feature\Event;

use App\Filament\Event\Resources\EventResource;
use App\Modules\Event\Models\EtAccess;
use App\Modules\Event\Models\EtEvent;
use App\Modules\Event\Models\EtParticipant;
use App\Modules\Event\Models\EtScanPoint;
use App\Modules\Event\Models\EtStation;
use App\Modules\Event\Models\EtVisit;
use App\Modules\Event\Policies\EventPolicy;
use App\Modules\Event\Services\EventSetupService;
use App\Modules\Event\Services\ScanService;
use App\Support\Akses;
use RuntimeException;

class EventSetupTest extends EventTestCase
{
    /** @return array<string, mixed> */
    private function data(int $nupEventId, array $akses = []): array
    {
        $svc = app(EventSetupService::class);
        $pos = [
            ['code' => 'kons', 'name' => 'Konsultasi', 'target_minutes' => 10],
            ['code' => 'KASIR', 'name' => 'Kasir', 'target_minutes' => null],
            ['code' => 'PU', 'name' => 'Pilih Unit', 'target_minutes' => 20],
        ];
        $titik = $svc->titikBawaan($pos);
        $titik[1]['mode'] = EventSetupService::MODE_SINGLE;   // Kasir: satu HP

        return [
            'name' => 'Pilih Unit Jasmine', 'event_date' => '2026-11-20', 'venue' => 'Marketing Gallery',
            'fo_nup_event_id' => $nupEventId, 'pos' => $pos, 'titik' => $titik, 'akses' => $akses,
        ];
    }

    public function test_create_builds_stations_scan_points_and_access(): void
    {
        $ev = $this->foNupEvent();
        $super = $this->superUser();
        $scanner = $this->user();
        $koor = $this->user();

        $event = app(EventSetupService::class)->create($this->data($ev, [
            ['user_id' => $scanner->id, 'titik' => ['KONS:masuk', 'KASIR:single'], 'can_live_board' => false, 'can_reports' => false],
            ['user_id' => $koor->id, 'titik' => [], 'can_live_board' => true, 'can_reports' => true],
        ]), $super);

        $this->assertSame(EtEvent::DRAFT, $event->status);
        $st = $event->stations()->get();
        $this->assertSame(['FO', 'KONS', 'KASIR', 'PU'], $st->pluck('code')->all());
        $this->assertSame([1, 2, 3, 4], $st->pluck('sequence')->all());
        $this->assertSame([EtStation::FO_CHECKIN, EtStation::NORMAL, EtStation::NORMAL, EtStation::FINISH], $st->pluck('type')->all());
        $this->assertSame(0, $st[0]->scanPoints()->count());
        $this->assertSame(['masuk', 'keluar'], $st[1]->scanPoints()->pluck('kind')->all());
        $this->assertSame(['single'], $st[2]->scanPoints()->pluck('kind')->all());

        $this->assertSame(2, EtAccess::where('user_id', $scanner->id)->whereNotNull('scan_point_id')->count());
        $koorRow = EtAccess::where('user_id', $koor->id)->sole();
        $this->assertNull($koorRow->scan_point_id);
        $this->assertTrue($koorRow->can_live_board && $koorRow->can_reports);

        // Bentuk data kembali utuh untuk form ubah.
        $form = app(EventSetupService::class)->formData($event);
        $this->assertSame(['KONS', 'KASIR', 'PU'], array_column($form['pos'], 'code'));
        $this->assertEqualsCanonicalizing(['KONS:masuk', 'KASIR:single'], collect($form['akses'])->firstWhere('user_id', $scanner->id)['titik']);
    }

    public function test_validation_messages(): void
    {
        $ev = $this->foNupEvent();
        $svc = app(EventSetupService::class);
        $super = $this->superUser();

        $d = $this->data($ev);
        $d['pos'][1]['code'] = 'KONS';
        try {
            $svc->create($d, $super);
            $this->fail('kode ganda harus ditolak');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('tidak boleh sama', $e->getMessage());
        }

        $this->expectExceptionMessage('Event NUP FO tidak ditemukan.');
        $svc->create($this->data(999999), $super);
    }

    public function test_open_syncs_close_ends_scanner_access_and_open_visits(): void
    {
        $ev = $this->foNupEvent();
        $this->foNup($ev, $this->foCustomer('C-SET'), '0700');
        $super = $this->superUser();
        $svc = app(EventSetupService::class);

        $event = $svc->create($this->data($ev) + ['buka_sekarang' => true], $super);
        $this->assertSame(EtEvent::OPEN, $event->status);
        $this->assertSame(1, EtParticipant::where('event_id', $event->id)->count());   // tersinkron saat dibuka

        $masuk = EtScanPoint::whereHas('station', fn ($q) => $q->where('event_id', $event->id)->where('code', 'KONS'))->where('kind', 'masuk')->sole();
        $this->assertTrue(app(ScanService::class)->handle($masuk->id, '0700', $super)->ok());

        $svc->close($event, $super);
        $this->assertSame(EtVisit::END_EVENT_CLOSED, EtVisit::sole()->end_reason);
        $this->assertSame('Event sudah ditutup', app(ScanService::class)->handle($masuk->id, '0700', $super)->message);
    }

    public function test_structure_locked_after_open_and_duplicate_copies_setup(): void
    {
        $ev = $this->foNupEvent();
        $super = $this->superUser();
        $scanner = $this->user();
        $svc = app(EventSetupService::class);

        $event = $svc->create($this->data($ev, [
            ['user_id' => $scanner->id, 'titik' => ['PU:keluar'], 'can_live_board' => true, 'can_reports' => false],
        ]) + ['buka_sekarang' => true], $super);
        $stationIds = $event->stations()->pluck('id')->all();

        $d = $svc->formData($event);   // seperti form ubah: akses ikut terkirim
        $d['name'] = 'Nama baru';
        $d['pos'] = [['code' => 'X', 'name' => 'Lain']];
        $svc->update($event, $d);
        $this->assertSame('Nama baru', $event->fresh()->name);
        $this->assertSame($stationIds, $event->stations()->pluck('id')->all());   // pos tak berubah

        $copy = $svc->duplicate($event->fresh(), $super);
        $this->assertSame(EtEvent::DRAFT, $copy->status);
        $this->assertSame('Nama baru (salinan)', $copy->name);
        $this->assertSame(['FO', 'KONS', 'KASIR', 'PU'], $copy->stations()->pluck('code')->all());
        $this->assertSame(1, EtAccess::where('event_id', $copy->id)->where('user_id', $scanner->id)->count());
    }

    public function test_setup_is_superadmin_only_even_in_report_mode(): void
    {
        config(['akses.tegakkan' => false]);   // mode lapor: Akses::boleh selalu true
        $ev = $this->foNupEvent();
        $super = $this->superUser();
        $scanner = $this->user();
        $orang = $this->user();
        $event = app(EventSetupService::class)->create($this->data($ev, [
            ['user_id' => $scanner->id, 'titik' => ['KONS:masuk'], 'can_live_board' => false, 'can_reports' => false],
        ]), $super);
        $this->eventOpen($this->foNupEvent('LAIN'));   // event lain tanpa akses

        $this->actingAs($scanner);
        $this->assertTrue(EventResource::canViewAny());
        $this->assertFalse(EventResource::canCreate());
        $this->assertFalse(EventResource::canEdit($event));
        $this->assertSame([$event->id], EventResource::getEloquentQuery()->pluck('id')->all());

        $this->actingAs($orang);
        $this->assertSame([], EventResource::getEloquentQuery()->pluck('id')->all());

        foreach (config('akses.peran_super') as $peran) {
            $this->actingAs($this->superUser($peran));
            $this->assertTrue(EventResource::canCreate(), $peran);
            $this->assertCount(2, EventResource::getEloquentQuery()->get(), $peran);
        }

        $policy = app(EventPolicy::class);
        $this->assertFalse($policy->canLiveBoard($scanner, $event));
        $this->assertTrue($policy->canLiveBoard($super, $event));
    }

    public function test_resource_and_panel_follow_akses_mapping(): void
    {
        $this->assertSame('event.event', Akses::awalanResource(EventResource::class));
        $this->assertSame('panel.event', config('akses.panel.event'));
        $this->assertSame('panel.event', config('akses.modul.event'));
    }
}
