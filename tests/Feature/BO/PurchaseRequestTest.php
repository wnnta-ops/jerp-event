<?php

namespace Tests\Feature\BO;

use App\Models\Company;
use App\Modules\BO\PurchaseRequest\Models\PurchaseRequest;
use App\Modules\BO\PurchaseRequest\Services\PurchaseRequestService;
use Database\Seeders\BoNumeratorSeeder;
use Database\Seeders\TenantSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class PurchaseRequestTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private PurchaseRequestService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TenantSeeder::class);
        $this->company = Company::create(['code' => 'MUP', 'name' => 'PT MUP', 'is_active' => true]);
        $this->seed(BoNumeratorSeeder::class);
        $this->service = app(PurchaseRequestService::class);
    }

    private function makePr(): PurchaseRequest
    {
        $pr = PurchaseRequest::create([
            'no_pr' => $this->service->nextPrNumber($this->company->id),
            'tgl' => '2026-05-31',
            'company_id' => $this->company->id,
            'keperluan' => 'Material proyek',
            'estimasi_total' => 15_000_000,
            'status' => 'DRAFT',
        ]);
        $pr->lines()->create(['urutan' => 1, 'deskripsi' => 'Semen', 'qty' => 100, 'satuan' => 'SAK', 'harga_estimasi' => 150_000, 'subtotal' => 15_000_000]);

        return $pr;
    }

    public function test_two_step_approval_then_convert_to_po(): void
    {
        $pr = $this->makePr();

        $pr = $this->service->approve($pr);
        $this->assertSame('APPROVED_1', $pr->status);

        $pr = $this->service->approve($pr);
        $this->assertSame('APPROVED', $pr->status);

        $po = $this->service->convertToPo($pr);
        $this->assertSame('PO', $pr->refresh()->status);
        $this->assertSame($po->id, $pr->po_id);
        $this->assertCount(1, $po->lines);
        $this->assertEqualsWithDelta(15_000_000, (float) $po->total, 0.01);
    }

    public function test_cannot_convert_before_full_approval(): void
    {
        $pr = $this->service->approve($this->makePr()); // only level 1
        $this->expectException(RuntimeException::class);
        $this->service->convertToPo($pr);
    }

    public function test_reject_blocks_further_flow(): void
    {
        $pr = $this->service->reject($this->makePr(), 'budget tidak ada');
        $this->assertSame('REJECTED', $pr->status);

        $this->expectException(RuntimeException::class);
        $this->service->approve($pr);
    }
}
