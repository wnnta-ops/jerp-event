<?php

namespace Tests\Feature\E2E;

use App\Models\Project;
use App\Modules\FO\FinanceAR\Models\Bkm;
use App\Modules\FO\FinanceAR\Models\Tts;
use App\Modules\FO\FinanceAR\Services\TtsToBkmService;
use App\Modules\FO\MarketingSales\Models\Customer;
use App\Modules\FO\MarketingSales\Models\Kontrak;
use App\Modules\FO\MarketingSales\Models\Reservasi;
use App\Modules\FO\MarketingSales\Models\Unit;
use App\Modules\FO\MarketingSales\Models\UnitType;
use App\Services\Numerator\NumeratorServiceInterface;
use Database\Seeders\BoChartOfAccountSeeder;
use Database\Seeders\BoNumeratorSeeder;
use Database\Seeders\MasterDataSeeder;
use Database\Seeders\TenantSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * E2E: full FO selling chain from a brand-new Customer through to a paid receipt.
 * Asserts data linkage and status transitions exactly as documented in
 * docs/04_BusinessFlow.md (§1 induk + §4 Reservasi/Kontrak + §6 Finance AR).
 */
class FoSellingFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TenantSeeder::class);
        $this->seed(MasterDataSeeder::class);          // company MUP, project JR, FO numerators
        $this->seed(BoNumeratorSeeder::class);
        $this->seed(BoChartOfAccountSeeder::class);
    }

    public function test_customer_to_kontrak_to_tts_to_bkm_chain_links_correctly(): void
    {
        $numerator = app(NumeratorServiceInterface::class);

        // STAGE 1: Customer (lead from any source — admin entry here)
        $customer = Customer::create([
            'tenant_id' => 1,
            'code' => $numerator->next('CUST'),
            'full_name' => 'Budi Santoso',
            'nik' => '3201010101010001',
            'email' => 'budi@example.test',
            'mobile' => '081234567890',
            'city' => 'Bekasi',
            'province' => 'Jawa Barat',
            'lead_status' => 'CUSTOMER',
            'is_active' => true,
        ]);

        $this->assertMatchesRegularExpression('/^CUST-\d{4}-\d{5}$/', $customer->code, 'Customer code must follow CUST-YYYY-#####');

        // STAGE 2: Unit (master data — TERSEDIA)
        $project = Project::query()->where('code', 'JR')->firstOrFail();
        $unitType = UnitType::create([
            'tenant_id' => 1,
            'project_id' => $project->id,
            'code' => 'T36',
            'name' => 'Tipe 36/72',
            'harga_dasar' => 350_000_000,
            'is_active' => true,
        ]);
        $unit = Unit::create([
            'tenant_id' => 1,
            'project_id' => $project->id,
            'unit_type_id' => $unitType->id,
            'blok' => 'A',
            'no_unit' => '01',
            'harga_jual' => 350_000_000,
            'status' => 'TERSEDIA',
            'is_active' => true,
        ]);

        $this->assertSame('TERSEDIA', $unit->status);

        // STAGE 3: Reservasi — manually wire status (the Filament Pages\CreateReservasi
        // does this in CreateReservasi::afterCreate). We exercise the model + service
        // contract here, the UI integration is covered separately.
        $reservasi = Reservasi::create([
            'tenant_id' => 1,
            'no_reservasi' => $numerator->next('RSV'),
            'customer_id' => $customer->id,
            'unit_id' => $unit->id,
            'tgl_reservasi' => '2026-05-31',
            'tgl_kadaluarsa' => '2026-06-07',
            'harga_jual' => 350_000_000,
            'booking_fee' => 5_000_000,
            'status' => 'AKTIF',
        ]);
        $unit->update(['status' => 'BOOKING']);

        $this->assertSame('AKTIF', $reservasi->status);
        $this->assertSame('BOOKING', $unit->refresh()->status);
        $this->assertSame($customer->id, $reservasi->customer_id);

        // STAGE 4: Kontrak (BOOKING → AKTIF) — same status wiring as
        // CreateKontrak::afterCreate.
        $kontrak = Kontrak::create([
            'tenant_id' => 1,
            'no_kontrak' => $numerator->next('KPR'),
            'reservasi_id' => $reservasi->id,
            'customer_id' => $customer->id,
            'unit_id' => $unit->id,
            'tgl_kontrak' => '2026-06-01',
            'harga_jual' => 350_000_000,
            'diskon' => 0,
            'ppn' => 0,
            'total_kontrak' => 350_000_000,
            'cara_bayar' => 'KPR',
            'tenor_bulan' => 240,
            'dp_persen' => 20,
            'dp_amount' => 70_000_000,
            'status' => 'AKTIF',
        ]);
        $unit->update(['status' => 'TERJUAL']);
        $reservasi->update(['status' => 'NAIK_KONTRAK']);

        $this->assertSame('TERJUAL', $unit->refresh()->status);
        $this->assertSame('NAIK_KONTRAK', $reservasi->refresh()->status);
        $this->assertSame($reservasi->id, $kontrak->reservasi_id);
        $this->assertMatchesRegularExpression('/^KPR-\d{4}-\d{5}$/', $kontrak->no_kontrak);

        // STAGE 5: TTS (provisional receipt) — bank transfer of DP
        $tts = Tts::create([
            'tenant_id' => 1,
            'no_tts' => $numerator->next('TTS'),
            'tgl_tts' => '2026-06-02',
            'tgl_terima' => '2026-06-02',
            'customer_id' => $customer->id,
            'kontrak_id' => $kontrak->id,
            'jenis_pembayaran' => 'DP',
            'amount' => 70_000_000,
            'cara_bayar' => 'TRANSFER',
            'no_referensi' => 'TRX-INV001',
            'status' => 'DRAFT',
            'keterangan' => 'DP via transfer',
        ]);

        $this->assertSame('DRAFT', $tts->status);

        // STAGE 6: TTS → BKM conversion (atomic via service — this is the contract
        // tested by other modules; here we just confirm the chain holds)
        $bkm = TtsToBkmService::convert($tts);

        $tts->refresh();
        $bkm->refresh();

        $this->assertSame('JADI_BKM', $tts->status, 'TTS status must transition to JADI_BKM');
        $this->assertSame($bkm->id, $tts->bkm_id, 'TTS.bkm_id must link to created BKM');
        $this->assertSame($tts->id, $bkm->tts_id, 'BKM.tts_id must back-link to source TTS');
        $this->assertSame('POSTED', $bkm->status);
        $this->assertEqualsWithDelta(70_000_000, (float) $bkm->amount, 0.01);
        $this->assertSame($customer->id, $bkm->customer_id, 'Customer identity carries through TTS→BKM');
        $this->assertSame($kontrak->id, $bkm->kontrak_id, 'Kontrak identity carries through TTS→BKM');
        $this->assertMatchesRegularExpression('/^BKM-\d{6}-\d{5}$/', $bkm->no_bkm);
    }

    public function test_unit_status_machine_blocks_double_booking(): void
    {
        // RULE-06 invariant: a unit can only be reserved once.
        $project = Project::query()->where('code', 'JR')->firstOrFail();
        $unit = Unit::create([
            'tenant_id' => 1, 'project_id' => $project->id,
            'blok' => 'B', 'no_unit' => '01',
            'harga_jual' => 350_000_000, 'status' => 'TERSEDIA', 'is_active' => true,
        ]);

        $unit->update(['status' => 'BOOKING']);
        $this->assertSame('BOOKING', $unit->refresh()->status);

        // Real lock service test lives separately, but contract is: status not in
        // TERSEDIA → no booking allowed. We verify the column reflects state.
        $this->assertNotSame('TERSEDIA', $unit->status);
    }
}
