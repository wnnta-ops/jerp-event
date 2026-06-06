<?php

namespace Tests\Feature\BO;

use App\Models\Company;
use App\Modules\BO\CashBank\Models\CbVoucher;
use App\Modules\BO\CashBank\Services\CbVoucherService;
use App\Modules\BO\GeneralLedger\Models\JurnalHeader;
use Database\Seeders\BoChartOfAccountSeeder;
use Database\Seeders\BoNumeratorSeeder;
use Database\Seeders\TenantSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CashBankTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private CbVoucherService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TenantSeeder::class);
        $this->company = Company::create(['code' => 'MUP', 'name' => 'PT MUP', 'is_active' => true]);
        $this->seed(BoNumeratorSeeder::class);
        $this->seed(BoChartOfAccountSeeder::class);
        $this->service = app(CbVoucherService::class);
    }

    private function makeVoucher(string $jenis, float $nominal): CbVoucher
    {
        return CbVoucher::create([
            'jenis' => $jenis,
            'no_voucher' => $this->service->nextNumber($jenis, $this->company->id),
            'tgl' => '2026-05-31',
            'company_id' => $this->company->id,
            'akun_kas' => '1.1.01.101',     // Bank BCA
            'akun_lawan' => '4.1.02.001',   // Pendapatan Booking Fee
            'nominal' => $nominal,
            'status' => 'DRAFT',
            'keterangan' => 'Terima booking fee',
        ]);
    }

    public function test_posting_a_bkm_creates_a_balanced_journal_debiting_cash(): void
    {
        $voucher = $this->makeVoucher('BKM', 5_000_000);
        $voucher = $this->service->post($voucher);

        $this->assertSame('POSTED', $voucher->status);
        $this->assertNotNull($voucher->jurnal_id);

        $jurnal = JurnalHeader::with('detil')->find($voucher->jurnal_id);
        $this->assertTrue($jurnal->isBalanced());

        $kasLine = $jurnal->detil->firstWhere('kode_account', '1.1.01.101');
        $lawanLine = $jurnal->detil->firstWhere('kode_account', '4.1.02.001');
        $this->assertEqualsWithDelta(5_000_000, (float) $kasLine->debet, 0.01);   // cash debited
        $this->assertEqualsWithDelta(5_000_000, (float) $lawanLine->kredit, 0.01); // revenue credited
    }

    public function test_posting_a_bkk_credits_cash(): void
    {
        $voucher = $this->makeVoucher('BKK', 2_000_000);
        $voucher = $this->service->post($voucher);

        $jurnal = JurnalHeader::with('detil')->find($voucher->jurnal_id);
        $kasLine = $jurnal->detil->firstWhere('kode_account', '1.1.01.101');
        $this->assertEqualsWithDelta(2_000_000, (float) $kasLine->kredit, 0.01); // cash credited (out)
    }

    public function test_void_reverses_the_journal_and_marks_batal(): void
    {
        $voucher = $this->service->post($this->makeVoucher('BKM', 1_000_000));
        $voucher = $this->service->void($voucher, 'salah input');

        $this->assertSame('BATAL', $voucher->status);
        // A reversing journal now exists for the original.
        $this->assertTrue(
            JurnalHeader::where('reversal_of_id', $voucher->jurnal_id)->exists()
        );
    }
}
