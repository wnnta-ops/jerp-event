<?php

namespace Tests\Feature\BO;

use App\Modules\BO\Inventory\Models\IvtItem;
use App\Modules\BO\Inventory\Models\IvtMovement;
use App\Modules\BO\Inventory\Services\InventoryService;
use Database\Seeders\BoNumeratorSeeder;
use Database\Seeders\TenantSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class InventoryTest extends TestCase
{
    use RefreshDatabase;

    private InventoryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TenantSeeder::class);
        $this->seed(BoNumeratorSeeder::class);
        $this->service = app(InventoryService::class);
    }

    private function item(float $stok = 0): IvtItem
    {
        return IvtItem::create(['kode' => 'SMN', 'nama' => 'Semen 50kg', 'satuan' => 'SAK', 'stok' => $stok]);
    }

    private function movement(IvtItem $item, string $jenis, float $qty): IvtMovement
    {
        return IvtMovement::create([
            'no_movement' => $this->service->nextNumber(),
            'jenis' => $jenis,
            'tgl' => '2026-05-31',
            'item_id' => $item->id,
            'arah' => IvtMovement::arahFor($jenis),
            'qty' => $qty,
            'status' => 'DRAFT',
        ]);
    }

    public function test_posting_a_masuk_movement_increases_stock(): void
    {
        $item = $this->item(10);
        $this->service->post($this->movement($item, 'BBB', 40));

        $this->assertEqualsWithDelta(50, (float) $item->refresh()->stok, 0.01);
    }

    public function test_posting_a_keluar_movement_decreases_stock(): void
    {
        $item = $this->item(50);
        $this->service->post($this->movement($item, 'BKB', 20));

        $this->assertEqualsWithDelta(30, (float) $item->refresh()->stok, 0.01);
    }

    public function test_keluar_more_than_stock_is_rejected(): void
    {
        $item = $this->item(5);
        $this->expectException(RuntimeException::class);
        $this->service->post($this->movement($item, 'BKB', 10));
    }

    public function test_fpb_requires_approval_before_posting(): void
    {
        $item = $this->item(100);
        $fpb = $this->movement($item, 'FPB', 10);

        try {
            $this->service->post($fpb);
            $this->fail('Expected approval requirement.');
        } catch (RuntimeException) {
            // expected
        }

        $this->service->approve($fpb);
        $this->service->post($fpb->refresh());
        $this->assertEqualsWithDelta(90, (float) $item->refresh()->stok, 0.01);
    }

    public function test_void_restores_stock(): void
    {
        $item = $this->item(0);
        $mov = $this->movement($item, 'BBB', 30);
        $this->service->post($mov);
        $this->assertEqualsWithDelta(30, (float) $item->refresh()->stok, 0.01);

        $this->service->void($mov->refresh());
        $this->assertEqualsWithDelta(0, (float) $item->refresh()->stok, 0.01);
    }
}
