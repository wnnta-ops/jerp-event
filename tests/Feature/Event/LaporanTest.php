<?php

namespace Tests\Feature\Event;

use App\Filament\Event\Pages\Laporan;
use App\Modules\Event\Exports\EventExport;
use App\Modules\Event\Models\EtAccess;
use App\Modules\Event\Models\EtEvent;
use App\Modules\Event\Services\EventReportService;
use App\Modules\Event\Services\ScanService;
use Database\Seeders\EventDemoSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;

class LaporanTest extends EventTestCase
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

    public function test_percentile_and_duration_helpers(): void
    {
        $this->assertSame(30, EventReportService::persentil([10, 20, 30, 40, 50], 50));
        $this->assertSame(46, EventReportService::persentil([10, 20, 30, 40, 50], 90));   // PERCENTILE.INC
        $this->assertNull(EventReportService::persentil([], 90));
        $this->assertSame(25, EventReportService::rata([20, 30]));
        $this->assertSame('06:12', EventReportService::durasi(372));
        $this->assertSame('1:02:03', EventReportService::durasi(3723));
        $this->assertSame('—', EventReportService::durasi(null));
    }

    public function test_per_pos_service_and_waiting_times(): void
    {
        $ev = $this->foNupEvent();
        $this->foNup($ev, $this->foCustomer('C-R1', 'Satu'), '5001', ['tgl_check_in' => '2026-11-20 09:50:00']);
        $this->foNup($ev, $this->foCustomer('C-R2', 'Dua'), '5002', ['tgl_check_in' => '2026-11-20 09:55:00']);
        $e = $this->eventOpen($ev);   // Konsultasi target 10, Pilih Unit target 15 (finish)
        $u = $this->superUser();
        $s = app(ScanService::class);
        $at = function (string $t, string $pt, string $nup) use ($s, $e, $u) {
            Carbon::setTestNow('2026-11-20 '.$t);
            $this->assertTrue($s->handle($e[$pt]->id, $nup, $u)->ok());
        };

        // 5001: tunggu 10 mnt, layanan 8 mnt, tunggu 2 mnt, layanan 20 mnt (lewat target 15).
        $at('10:00:00', 'm2', '5001');
        $at('10:08:00', 'k2', '5001');
        $at('10:10:00', 'm3', '5001');
        $at('10:30:00', 'k3', '5001');
        // 5002: tunggu 7 mnt, layanan 12 mnt (lewat target 10), masih menunggu Pilih Unit.
        $at('10:02:00', 'm2', '5002');
        $at('10:14:00', 'k2', '5002');

        $r = app(EventReportService::class);
        $pos = collect($r->perPos($e['event']))->keyBy('pos');
        $this->assertSame(2, $pos['Konsultasi']['selesai']);
        $this->assertSame(600, $pos['Konsultasi']['layanan_rata']);          // (8+12)/2 mnt
        $this->assertSame(510, $pos['Konsultasi']['tunggu_rata']);           // (10+7)/2 mnt
        $this->assertSame(1, $pos['Konsultasi']['lewat_target']);
        $this->assertSame(50.0, $pos['Konsultasi']['lewat_target_persen']);
        $this->assertSame(1200, $pos['Pilih Unit']['layanan_median']);
        $this->assertSame(120, $pos['Pilih Unit']['tunggu_rata']);

        $rk = $r->ringkasan($e['event']);
        $this->assertSame(2, $rk['peserta']);
        $this->assertSame(1, $rk['selesai']);
        $this->assertSame(2400, $rk['total_median']);                         // 09:50 → 10:30

        $c = collect($r->perCustomer($e['event']))->keyBy('no_nup');
        $this->assertSame(2400, $c['5001']['total_detik']);
        $this->assertSame(720, $c['5001']['tunggu_total_detik']);
        $this->assertSame(['Pilih Unit'], $c['5002']['dilewati']);

        $jam = $r->throughputPerJam($e['event']);
        $this->assertSame('2026-11-20 10:00', $jam[0]['jam']);
        $this->assertSame(1, $jam[0]['tuntas']);
        $this->assertSame(2, $jam[0]['per_pos'][$e['s2']->id]);

        // Filter pos & waktu.
        $this->assertCount(1, $r->perPos($e['event'], ['pos' => [$e['s3']->id]]));
        $this->assertCount(1, $r->kunjungan($e['event'], ['dari' => '2026-11-20 10:05']));
        $this->assertSame(1, $r->ringkasan($e['event'], ['status' => ['finished']])['peserta']);
    }

    public function test_export_has_five_sheets_and_page_downloads(): void
    {
        $u = $this->superUser();
        $this->projectId();
        $this->seed(EventDemoSeeder::class);
        $e = EtEvent::where('name', EventDemoSeeder::NAMA)->sole();

        $sheets = (new EventExport($e))->sheets();
        $this->assertSame(['Ringkasan', 'Per Pos', 'Per Customer', 'Kunjungan', 'Log Scan'], array_map(fn ($s) => $s->title(), $sheets));
        $this->assertCount(4, $sheets[1]->array());                // 4 pos
        $this->assertCount(29, $sheets[2]->array());               // 29 peserta
        $this->assertContains('Validasi Berkas (mnt)', $sheets[2]->headings());
        $this->assertNotEmpty($sheets[3]->array());
        $this->assertNotEmpty($sheets[4]->array());

        Excel::fake();
        Livewire::actingAs($u)->test(Laporan::class, ['eventId' => $e->id])
            ->assertOk()->assertSee('Per pos')->assertSee('Throughput per jam')->assertSee('Citra Lestari')
            ->set('pos', [$e->stations()->where('code', 'PU')->value('id')])
            ->callAction('export');
        Excel::assertDownloaded('laporan-event-demo-pilih-unit-live-board-'.now()->format('Ymd-Hi').'.xlsx',
            fn (EventExport $x) => count($x->sheets()[1]->array()) === 1);
    }

    public function test_access_super_or_can_reports_only(): void
    {
        $e = $this->eventOpen($this->foNupEvent());
        $analis = $this->user();
        $scanner = $this->user();
        EtAccess::create(['event_id' => $e['event']->id, 'user_id' => $analis->id, 'can_reports' => true]);
        $this->grantScan($scanner, $e['event'], $e['m2']);

        $this->actingAs($scanner);
        $this->assertFalse(Laporan::canAccess());
        $this->actingAs($analis);
        $this->assertTrue(Laporan::canAccess());
        $this->actingAs($this->superUser());
        $this->assertTrue(Laporan::canAccess());
    }
}
