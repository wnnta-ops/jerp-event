<?php

namespace Tests\Feature\FO;

use App\Models\Project;
use App\Modules\FO\Adminjual\Models\Skema;
use App\Modules\FO\Launching\Models\NupEvent;
use App\Modules\FO\Launching\Models\NupRegistration;
use App\Modules\FO\Launching\Services\ClosingNupService;
use App\Modules\FO\MarketingSales\Models\Customer;
use App\Modules\FO\MarketingSales\Models\Reservasi;
use App\Modules\FO\MarketingSales\Models\Unit;
use App\Modules\FO\MarketingSales\Models\UnitPricelist;
use Database\Seeders\MasterDataSeeder;
use Database\Seeders\TenantSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClosingNupTest extends TestCase
{
    use RefreshDatabase;

    public function test_closing_creates_kontrak_and_tts(): void
    {
        $this->seed(TenantSeeder::class);
        $this->seed(MasterDataSeeder::class);

        $project = Project::where('code', 'JR')->firstOrFail();
        $customer = Customer::create(['tenant_id' => 1, 'code' => 'CUST-X', 'project_id' => $project->id, 'full_name' => 'Anik Irawati', 'is_active' => true]);
        $unit = Unit::create(['tenant_id' => 1, 'project_id' => $project->id, 'blok' => 'IX', 'no_unit' => '15', 'harga_jual' => 500_000_000, 'status' => 'BOOKING', 'is_active' => true]);
        $skema = Skema::create(['tenant_id' => 1, 'project_id' => $project->id, 'code' => 'KPR-001', 'name' => 'KPR', 'cara_bayar' => 'KPR', 'dp_persen' => 5, 'jumlah_termin' => 1, 'is_active' => true]);
        UnitPricelist::create(['tenant_id' => 1, 'unit_id' => $unit->id, 'skema_id' => $skema->id, 'price_list' => 505_000_000, 'price_list_minimum' => 505_000_000, 'price_list_kavling' => 0, 'price_list_kavling_minimum' => 0]);

        $reservasi = Reservasi::create(['tenant_id' => 1, 'no_reservasi' => 'RSV-1', 'customer_id' => $customer->id, 'unit_id' => $unit->id, 'tgl_reservasi' => now()->toDateString(), 'harga_jual' => 500_000_000, 'booking_fee' => 2_500_000, 'status' => 'AKTIF']);
        $event = NupEvent::create(['tenant_id' => 1, 'project_id' => $project->id, 'code' => 'LNC1', 'name' => 'Launching 1', 'tgl_launching' => now()->toDateString(), 'booking_fee' => 2_500_000, 'status' => 'OPEN']);
        $reg = NupRegistration::create([
            'tenant_id' => 1, 'nup_event_id' => $event->id, 'customer_id' => $customer->id, 'unit_id' => $unit->id,
            'reservasi_id' => $reservasi->id, 'no_nup' => 'NUP-1', 'nama_pendaftar' => 'Anik Irawati', 'tgl_daftar' => now(),
            'status' => 'PILIH_UNIT', 'verif_status' => NupRegistration::VERIF_APPROVED,
            'booking_fee_paid' => 2_500_000, 'xendit_status' => 'PAID', 'xendit_external_id' => 'UTJ-NUP-1',
        ]);

        $result = app(ClosingNupService::class)->close($reg, [
            'skema_id' => $skema->id, 'agent_id' => null, 'cara_bayar' => 'KPR', 'tgl_kontrak' => now()->toDateString(),
        ]);

        $kontrak = $result['kontrak'];
        $this->assertStringStartsWith('KPR-', $kontrak->no_kontrak);
        $this->assertEqualsWithDelta(505_000_000, (float) $kontrak->harga_jual, 0.01); // dari price list skema
        $this->assertEqualsWithDelta(25_250_000, (float) $kontrak->dp_amount, 0.01);    // 5% × 505jt
        $this->assertSame($skema->id, $kontrak->skema_id);

        $this->assertSame('TERJUAL', $unit->fresh()->status);
        $this->assertSame('NAIK_KONTRAK', $reservasi->fresh()->status);

        $tts = $result['tts'];
        $this->assertEqualsWithDelta(2_500_000, (float) $tts->amount, 0.01);
        $this->assertTrue($tts->skip_gl_posting);          // UTJ via Xendit sudah diposting
        $this->assertSame($kontrak->id, $tts->kontrak_id);
        $this->assertSame($tts->id, $reg->fresh()->tts_id);
    }

    public function test_closing_rejected_when_already_naik_kontrak(): void
    {
        $this->seed(TenantSeeder::class);
        $this->seed(MasterDataSeeder::class);
        $project = Project::where('code', 'JR')->firstOrFail();
        $customer = Customer::create(['tenant_id' => 1, 'full_name' => 'X', 'is_active' => true]);
        $unit = Unit::create(['tenant_id' => 1, 'project_id' => $project->id, 'blok' => 'A', 'no_unit' => '1', 'harga_jual' => 1, 'status' => 'TERJUAL', 'is_active' => true]);
        $skema = Skema::create(['tenant_id' => 1, 'project_id' => $project->id, 'code' => 'K', 'name' => 'K', 'cara_bayar' => 'KPR', 'dp_persen' => 0, 'jumlah_termin' => 1, 'is_active' => true]);
        $reservasi = Reservasi::create(['tenant_id' => 1, 'no_reservasi' => 'RSV-2', 'customer_id' => $customer->id, 'unit_id' => $unit->id, 'tgl_reservasi' => now()->toDateString(), 'harga_jual' => 1, 'booking_fee' => 0, 'status' => 'NAIK_KONTRAK']);
        $event = NupEvent::create(['tenant_id' => 1, 'project_id' => $project->id, 'code' => 'L', 'name' => 'L', 'tgl_launching' => now()->toDateString(), 'booking_fee' => 0, 'status' => 'OPEN']);
        $reg = NupRegistration::create(['tenant_id' => 1, 'nup_event_id' => $event->id, 'customer_id' => $customer->id, 'reservasi_id' => $reservasi->id, 'no_nup' => 'NUP-2', 'nama_pendaftar' => 'X', 'tgl_daftar' => now(), 'status' => 'PILIH_UNIT', 'verif_status' => NupRegistration::VERIF_APPROVED]);

        $this->expectExceptionMessage('sudah naik kontrak');
        app(ClosingNupService::class)->close($reg, ['skema_id' => $skema->id, 'cara_bayar' => 'KPR', 'tgl_kontrak' => now()->toDateString()]);
    }
}
