<?php

namespace Tests\Feature\BO;

use App\Models\Company;
use App\Modules\BO\AccountsPayable\Models\ApInvoice;
use App\Modules\BO\AccountsPayable\Models\Supplier;
use App\Modules\BO\AccountsPayable\Services\ApInvoiceService;
use Database\Seeders\BoChartOfAccountSeeder;
use Database\Seeders\BoNumeratorSeeder;
use Database\Seeders\TenantSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountsPayableTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private ApInvoiceService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TenantSeeder::class);
        $this->company = Company::create(['code' => 'MUP', 'name' => 'PT MUP', 'is_active' => true]);
        $this->seed(BoNumeratorSeeder::class);
        $this->seed(BoChartOfAccountSeeder::class);
        $this->service = app(ApInvoiceService::class);
    }

    private function makeInvoice(float $dpp, float $ppn = 0, float $pph = 0): ApInvoice
    {
        $supplier = Supplier::create(['kode' => 'SUP1', 'nama' => 'PT Beton Jaya', 'is_active' => true]);

        return ApInvoice::create([
            'no_invoice' => $this->service->nextNumber($this->company->id),
            'jenis' => 'NONPO',
            'tgl' => '2026-05-31',
            'jatuh_tempo' => '2026-06-30',
            'company_id' => $this->company->id,
            'supplier_id' => $supplier->id,
            'akun_beban' => '1.1.03.003', // persediaan material
            'akun_hutang' => '2.1.01.001',
            'nominal' => $dpp,
            'ppn' => $ppn,
            'pph' => $pph,
            'status' => 'DRAFT',
        ]);
    }

    public function test_posting_creates_balanced_journal_with_tax_lines(): void
    {
        // DPP 10jt + PPN 1.1jt - PPh 200rb → hutang 10.9jt
        $inv = $this->service->post($this->makeInvoice(10_000_000, 1_100_000, 200_000));

        $this->assertSame('POSTED', $inv->status);
        $this->assertEqualsWithDelta(10_900_000, (float) $inv->total, 0.01);
        $this->assertEqualsWithDelta(10_900_000, (float) $inv->sisa, 0.01);

        $jurnal = $inv->jurnal->load('detil');
        $this->assertTrue($jurnal->isBalanced());
        $hutang = $jurnal->detil->firstWhere('kode_account', '2.1.01.001');
        $this->assertEqualsWithDelta(10_900_000, (float) $hutang->kredit, 0.01);
        $ppn = $jurnal->detil->firstWhere('kode_account', ApInvoice::AKUN_PPN_MASUKAN);
        $this->assertEqualsWithDelta(1_100_000, (float) $ppn->debet, 0.01);
    }

    public function test_payment_issues_a_bkk_and_reduces_sisa(): void
    {
        $inv = $this->service->post($this->makeInvoice(5_000_000));
        $this->assertEqualsWithDelta(5_000_000, (float) $inv->sisa, 0.01);

        $inv = $this->service->pay($inv, 2_000_000, '1.1.01.101');
        $this->assertSame('SEBAGIAN', $inv->status);
        $this->assertEqualsWithDelta(3_000_000, (float) $inv->sisa, 0.01);

        // A posted BKK voucher now exists for this company.
        $this->assertDatabaseHas('bo_cb_vouchers', ['jenis' => 'BKK', 'status' => 'POSTED']);

        $inv = $this->service->pay($inv, 3_000_000, '1.1.01.101');
        $this->assertSame('LUNAS', $inv->status);
    }
}
