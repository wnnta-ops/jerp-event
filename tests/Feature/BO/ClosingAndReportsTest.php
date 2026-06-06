<?php

namespace Tests\Feature\BO;

use App\Models\Company;
use App\Modules\BO\GeneralLedger\Contracts\JurnalPostingServiceInterface;
use App\Modules\BO\GeneralLedger\DataTransferObjects\JurnalLineDTO;
use App\Modules\BO\GeneralLedger\Exceptions\ClosedPeriodException;
use App\Modules\BO\GeneralLedger\Services\FinancialReportService;
use App\Modules\BO\GeneralLedger\Services\PeriodClosingService;
use App\Modules\BO\GeneralLedger\Support\AdHocPosting;
use Database\Seeders\BoChartOfAccountSeeder;
use Database\Seeders\BoNumeratorSeeder;
use Database\Seeders\TenantSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ClosingAndReportsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private JurnalPostingServiceInterface $posting;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TenantSeeder::class);
        $this->company = Company::create(['code' => 'MUP', 'name' => 'PT MUP', 'is_active' => true]);
        $this->seed(BoNumeratorSeeder::class);
        $this->seed(BoChartOfAccountSeeder::class);
        $this->posting = app(JurnalPostingServiceInterface::class);
    }

    private function postSale(float $amount, string $date = '2026-05-31'): void
    {
        $this->posting->post(AdHocPosting::make(
            lines: [
                JurnalLineDTO::debet('1.1.01.101', $amount),       // bank
                JurnalLineDTO::kredit('4.1.01.001', $amount),      // pendapatan penjualan
            ],
            documentType: 'JU',
            documentNo: 'TEST-'.$amount,
            companyId: $this->company->id,
            date: $date,
        ));
    }

    private function postExpense(float $amount, string $date = '2026-05-31'): void
    {
        $this->posting->post(AdHocPosting::make(
            lines: [
                JurnalLineDTO::debet('5.2.02.002', $amount),       // beban operasional
                JurnalLineDTO::kredit('1.1.01.101', $amount),      // bank
            ],
            documentType: 'JU',
            documentNo: 'EXP-'.$amount,
            companyId: $this->company->id,
            date: $date,
        ));
    }

    public function test_trial_balance_is_always_balanced(): void
    {
        $this->postSale(100_000_000);
        $this->postExpense(30_000_000);

        $totals = app(FinancialReportService::class)->trialBalanceTotals($this->company->id);
        $this->assertTrue($totals['seimbang']);
        $this->assertEqualsWithDelta($totals['total_debet'], $totals['total_kredit'], 0.01);
    }

    public function test_income_statement_and_balance_sheet_reconcile(): void
    {
        $this->postSale(100_000_000);
        $this->postExpense(30_000_000);

        $reports = app(FinancialReportService::class);
        $lr = $reports->labaRugi($this->company->id);
        $this->assertEqualsWithDelta(70_000_000, $lr['laba'], 0.01);

        $neraca = $reports->neraca($this->company->id);
        $this->assertTrue($neraca['seimbang'], 'Neraca harus seimbang (Aset = Liabilitas + Ekuitas + Laba).');
    }

    public function test_cannot_close_period_with_dangling_draft(): void
    {
        // Manual journal stays DRAFT.
        $this->posting->postManual(
            ['tgl_jurnal' => '2026-05-31', 'company_id' => $this->company->id],
            [
                ['kode_account' => '1.1.01.101', 'debet' => 1_000, 'kredit' => 0],
                ['kode_account' => '4.1.01.001', 'debet' => 0, 'kredit' => 1_000],
            ]
        );

        $this->expectException(RuntimeException::class);
        app(PeriodClosingService::class)->close($this->company->id, '2026-05');
    }

    public function test_closing_blocks_further_postings_in_that_period(): void
    {
        $this->postSale(5_000_000);
        app(PeriodClosingService::class)->close($this->company->id, '2026-05');

        $this->expectException(ClosedPeriodException::class);
        $this->postSale(1_000_000, '2026-05-20');
    }
}
