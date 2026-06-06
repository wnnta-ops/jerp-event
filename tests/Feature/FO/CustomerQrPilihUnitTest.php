<?php

namespace Tests\Feature\FO;

use App\Models\Project;
use App\Modules\FO\Launching\Models\NupEvent;
use App\Modules\FO\Launching\Models\NupRegistration;
use App\Modules\FO\Launching\Services\PilihUnitService;
use App\Modules\FO\MarketingSales\Models\Customer;
use App\Modules\FO\MarketingSales\Services\CustomerQrService;
use Database\Seeders\MasterDataSeeder;
use Database\Seeders\TenantSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerQrPilihUnitTest extends TestCase
{
    use RefreshDatabase;

    private function setupApprovedCheckedIn(string $custCode): NupRegistration
    {
        $project = Project::where('code', 'JR')->firstOrFail();
        $customer = Customer::create([
            'tenant_id' => 1, 'code' => $custCode, 'project_id' => $project->id,
            'full_name' => 'Budi Santoso', 'jalan' => 'Jl. Melati 5', 'city' => 'Sidoarjo',
            'lead_status' => 'CUSTOMER', 'is_active' => true,
        ]);
        $event = NupEvent::create([
            'tenant_id' => 1, 'project_id' => $project->id, 'code' => 'LNC1', 'name' => 'Launching 1',
            'tgl_launching' => '2026-06-10', 'booking_fee' => 3_000_000, 'status' => 'OPEN',
        ]);

        return NupRegistration::create([
            'tenant_id' => 1, 'nup_event_id' => $event->id, 'customer_id' => $customer->id,
            'no_nup' => 'NUP-1', 'urutan' => 1, 'nama_pendaftar' => 'budi santoso (typo)',
            'tgl_daftar' => now(), 'status' => 'CHECK_IN', 'verif_status' => NupRegistration::VERIF_APPROVED,
            'is_hold' => false,
        ]);
    }

    public function test_scan_customer_code_resolves_active_nup(): void
    {
        $this->seed(TenantSeeder::class);
        $this->seed(MasterDataSeeder::class);
        $reg = $this->setupApprovedCheckedIn('CUST-000777');

        $svc = app(PilihUnitService::class);

        // Scan kode customer (QR) → ketemu pendaftaran yang sama.
        $byCode = $svc->lookupByBarcode('CUST-000777');
        $this->assertSame($reg->id, $byCode->id);

        // Scan no_nup langsung → juga ketemu.
        $byNup = $svc->lookupByBarcode('NUP-1');
        $this->assertSame($reg->id, $byNup->id);

        // Nama mengikuti Master Customer (bukan nama_pendaftar yang typo).
        $this->assertSame('Budi Santoso', $byCode->customer->full_name);
    }

    public function test_ensure_code_issues_when_missing(): void
    {
        $this->seed(TenantSeeder::class);
        $this->seed(MasterDataSeeder::class); // menyediakan numerator CUST
        $customer = Customer::create(['tenant_id' => 1, 'full_name' => 'Tanpa Kode', 'is_active' => true]);
        $this->assertNull($customer->code);

        $code = app(CustomerQrService::class)->ensureCode($customer);
        $this->assertNotEmpty($code);
        $this->assertSame($code, $customer->fresh()->code);

        $svg = app(CustomerQrService::class)->svg($code, 200)->toHtml();
        $this->assertStringContainsString('<svg', $svg);
    }
}
