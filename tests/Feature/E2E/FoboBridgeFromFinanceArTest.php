<?php

namespace Tests\Feature\E2E;

use App\Models\Project;
use App\Modules\BO\FoBoBridge\Models\FoboPosting;
use App\Modules\BO\GeneralLedger\Models\JurnalDetil;
use App\Modules\BO\GeneralLedger\Models\JurnalHeader;
use App\Modules\FO\FinanceAR\Models\Tts;
use App\Modules\FO\FinanceAR\Services\TtsToBkmService;
use App\Modules\FO\MarketingSales\Models\Customer;
use App\Modules\FO\MarketingSales\Models\Kontrak;
use App\Modules\FO\MarketingSales\Models\Unit;
use App\Services\Numerator\NumeratorServiceInterface;
use Database\Seeders\BoChartOfAccountSeeder;
use Database\Seeders\BoNumeratorSeeder;
use Database\Seeders\MasterDataSeeder;
use Database\Seeders\TenantSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * E2E: real TtsToBkmService run that dispatches FoFinancialEvent → BO PostFoEventToGl
 * listener → bo_jurnal_header. Verifies FOBO bridge end-to-end from a live FO action
 * (not a synthetic FoFinancialEvent like FoBoBridgeTest covers).
 */
class FoboBridgeFromFinanceArTest extends TestCase
{
    use RefreshDatabase;

    private NumeratorServiceInterface $numerator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TenantSeeder::class);
        $this->seed(MasterDataSeeder::class);
        $this->seed(BoNumeratorSeeder::class);
        $this->seed(BoChartOfAccountSeeder::class);
        $this->numerator = app(NumeratorServiceInterface::class);
    }

    public function test_tts_to_bkm_conversion_posts_balanced_kas_masuk_to_general_ledger(): void
    {
        // Setup: minimum FO data to make a BKM
        $customer = Customer::create([
            'tenant_id' => 1,
            'code' => $this->numerator->next('CUST'),
            'full_name' => 'Siti Aminah',
            'nik' => '3201020202020001',
            'email' => 'siti@example.test',
            'is_active' => true,
        ]);

        $project = Project::query()->where('code', 'JR')->firstOrFail();
        $unit = Unit::create([
            'tenant_id' => 1, 'project_id' => $project->id,
            'blok' => 'C', 'no_unit' => '01', 'harga_jual' => 350_000_000,
            'status' => 'TERSEDIA', 'is_active' => true,
        ]);
        $kontrak = Kontrak::create([
            'tenant_id' => 1, 'no_kontrak' => $this->numerator->next('KPR'),
            'customer_id' => $customer->id, 'unit_id' => $unit->id,
            'tgl_kontrak' => '2026-06-01', 'harga_jual' => 350_000_000,
            'total_kontrak' => 350_000_000, 'cara_bayar' => 'KPR',
            'status' => 'AKTIF',
        ]);
        $tts = Tts::create([
            'tenant_id' => 1, 'no_tts' => $this->numerator->next('TTS'),
            'tgl_tts' => '2026-06-02', 'customer_id' => $customer->id,
            'kontrak_id' => $kontrak->id, 'jenis_pembayaran' => 'ANGSURAN',
            'amount' => 3_500_000, 'cara_bayar' => 'TRANSFER',
            'status' => 'DRAFT',
        ]);

        // ACT: convert TTS → BKM (this dispatches FoFinancialEvent::KAS_MASUK)
        $bkm = TtsToBkmService::convert($tts);

        // ASSERT 1: TTS → BKM linkage holds
        $this->assertSame('JADI_BKM', $tts->fresh()->status);
        $this->assertNotNull($bkm->id);

        // ASSERT 2: FOBO posting row was created (idempotency gate)
        $posting = FoboPosting::where('source_no', $bkm->no_bkm)
            ->where('jenis', 'KAS_MASUK')
            ->first();
        $this->assertNotNull($posting, 'FOBO posting row must exist for the BKM');
        $this->assertSame('POSTED', $posting->status);
        $this->assertNotNull($posting->jurnal_id, 'FOBO posting must reference a journal');

        // ASSERT 3: Balanced journal in BO GL
        $jurnal = JurnalHeader::with('detil')->find($posting->jurnal_id);
        $this->assertNotNull($jurnal);
        $this->assertSame('FOBO:KAS_MASUK', $jurnal->jenis_dokumen);
        $this->assertSame($bkm->no_bkm, $jurnal->no_dokumen);
        $this->assertTrue($jurnal->isBalanced(), 'Auto-posted journal must be balanced');

        // ASSERT 4: Default mapping is followed (Kas Besar D / Piutang Customer K)
        $debet = $jurnal->detil->firstWhere('kode_account', '1.1.01.002');
        $kredit = $jurnal->detil->firstWhere('kode_account', '1.1.02.001');
        $this->assertNotNull($debet, 'Debet leg on Kas Besar (1.1.01.002)');
        $this->assertNotNull($kredit, 'Kredit leg on Piutang Customer (1.1.02.001)');
        $this->assertEqualsWithDelta(3_500_000, (float) $debet->debet, 0.01);
        $this->assertEqualsWithDelta(3_500_000, (float) $kredit->kredit, 0.01);
        $this->assertEqualsWithDelta(0, (float) $debet->kredit, 0.01);
        $this->assertEqualsWithDelta(0, (float) $kredit->debet, 0.01);
    }

    public function test_converting_two_tts_creates_two_separate_balanced_journals(): void
    {
        // Verifies the FOBO chain is *per-document*, not aggregated.
        $customer = Customer::create([
            'tenant_id' => 1, 'code' => $this->numerator->next('CUST'),
            'full_name' => 'Andi Pratama', 'nik' => '3201030303030001', 'is_active' => true,
        ]);
        $project = Project::query()->where('code', 'JR')->firstOrFail();
        $unitA = Unit::create([
            'tenant_id' => 1, 'project_id' => $project->id, 'blok' => 'D', 'no_unit' => '01',
            'harga_jual' => 300_000_000, 'status' => 'TERSEDIA', 'is_active' => true,
        ]);
        $kontrak = Kontrak::create([
            'tenant_id' => 1, 'no_kontrak' => $this->numerator->next('KPR'),
            'customer_id' => $customer->id, 'unit_id' => $unitA->id,
            'tgl_kontrak' => '2026-06-01', 'harga_jual' => 300_000_000,
            'total_kontrak' => 300_000_000, 'cara_bayar' => 'KPR', 'status' => 'AKTIF',
        ]);

        $amounts = [2_000_000, 4_500_000];
        foreach ($amounts as $i => $amount) {
            $tts = Tts::create([
                'tenant_id' => 1, 'no_tts' => $this->numerator->next('TTS'),
                'tgl_tts' => '2026-06-0'.($i + 2), 'customer_id' => $customer->id,
                'kontrak_id' => $kontrak->id, 'jenis_pembayaran' => 'ANGSURAN',
                'amount' => $amount, 'cara_bayar' => 'CASH', 'status' => 'DRAFT',
            ]);
            TtsToBkmService::convert($tts);
        }

        $postings = FoboPosting::where('jenis', 'KAS_MASUK')->get();
        $this->assertCount(2, $postings, 'One FOBO posting per BKM');
        $this->assertSame(2, JurnalHeader::where('jenis_dokumen', 'FOBO:KAS_MASUK')->count());

        $totalDebet = JurnalDetil::whereIn('jurnal_id', $postings->pluck('jurnal_id'))->sum('debet');
        $this->assertEqualsWithDelta(6_500_000, (float) $totalDebet, 0.01,
            'Total debet across all auto-posted journals should equal sum of BKM amounts');
    }
}
