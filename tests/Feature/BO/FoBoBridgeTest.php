<?php

namespace Tests\Feature\BO;

use App\Models\Company;
use App\Modules\BO\FoBoBridge\Models\FoboPosting;
use App\Modules\BO\GeneralLedger\Models\JurnalHeader;
use App\Modules\FO\Shared\Events\FoFinancialEvent;
use Database\Seeders\BoChartOfAccountSeeder;
use Database\Seeders\BoNumeratorSeeder;
use Database\Seeders\TenantSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FoBoBridgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TenantSeeder::class);
        Company::create(['code' => 'MUP', 'name' => 'PT MUP', 'is_active' => true]);
        $this->seed(BoNumeratorSeeder::class);
        $this->seed(BoChartOfAccountSeeder::class);
    }

    public function test_fo_event_auto_posts_a_balanced_journal(): void
    {
        FoFinancialEvent::dispatch(
            jenis: 'KAS_MASUK',
            sourceModul: 'FO.FinanceAR',
            sourceNo: 'BKM-202605-00001',
            amount: 5_000_000,
            tgl: '2026-05-31',
            keterangan: 'Terima angsuran',
        );

        $posting = FoboPosting::where('source_no', 'BKM-202605-00001')->first();
        $this->assertNotNull($posting);
        $this->assertSame('POSTED', $posting->status);
        $this->assertNotNull($posting->jurnal_id);

        $jurnal = JurnalHeader::with('detil')->find($posting->jurnal_id);
        $this->assertTrue($jurnal->isBalanced());
        $this->assertSame('FOBO:KAS_MASUK', $jurnal->jenis_dokumen);
    }

    public function test_replaying_the_same_event_does_not_duplicate_the_journal(): void
    {
        $fire = fn () => FoFinancialEvent::dispatch(
            jenis: 'SALES',
            sourceModul: 'FO.MarketingSales',
            sourceNo: 'KTR-2026-00007',
            amount: 300_000_000,
            tgl: '2026-05-31',
        );

        $fire();
        $fire();
        $fire();

        // Exactly one posting + one journal despite three dispatches.
        $this->assertSame(1, FoboPosting::where('source_no', 'KTR-2026-00007')->count());
        $this->assertSame(1, JurnalHeader::where('no_dokumen', 'KTR-2026-00007')
            ->where('jenis_dokumen', 'FOBO:SALES')->count());
    }

    public function test_account_override_is_respected(): void
    {
        FoFinancialEvent::dispatch(
            jenis: 'KOMISI',
            sourceModul: 'FO.Komisi',
            sourceNo: 'KOM-202605-00003',
            amount: 12_000_000,
            akunDebet: '5.2.01.001',
            akunKredit: '1.1.01.002',
        );

        $posting = FoboPosting::where('source_no', 'KOM-202605-00003')->first();
        $jurnal = JurnalHeader::with('detil')->find($posting->jurnal_id);

        $debet = $jurnal->detil->firstWhere('kode_account', '5.2.01.001');
        $kredit = $jurnal->detil->firstWhere('kode_account', '1.1.01.002');
        $this->assertEqualsWithDelta(12_000_000, (float) $debet->debet, 0.01);
        $this->assertEqualsWithDelta(12_000_000, (float) $kredit->kredit, 0.01);
    }
}
