<?php

namespace Tests\Feature\BO;

use App\Models\Company;
use App\Modules\BO\AccountsReceivable\Models\ArInvoice;
use App\Modules\BO\AccountsReceivable\Services\ArAgingService;
use App\Modules\BO\AccountsReceivable\Services\ArInvoiceService;
use Database\Seeders\BoChartOfAccountSeeder;
use Database\Seeders\BoNumeratorSeeder;
use Database\Seeders\TenantSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AccountsReceivableTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private ArInvoiceService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TenantSeeder::class);
        $this->company = Company::create(['code' => 'MUP', 'name' => 'PT MUP', 'is_active' => true]);
        $this->seed(BoNumeratorSeeder::class);
        $this->seed(BoChartOfAccountSeeder::class);
        $this->service = app(ArInvoiceService::class);
    }

    private function makeInvoice(float $nominal, string $jatuhTempo = '2026-06-30'): ArInvoice
    {
        return ArInvoice::create([
            'no_invoice' => $this->service->nextNumber($this->company->id),
            'jenis' => 'FI',
            'tgl' => '2026-05-31',
            'jatuh_tempo' => $jatuhTempo,
            'company_id' => $this->company->id,
            'customer_nama' => 'Budi',
            'nominal' => $nominal,
            'sisa' => $nominal,
            'status' => 'DRAFT',
        ]);
    }

    public function test_posting_opens_invoice_and_creates_balanced_journal(): void
    {
        $inv = $this->service->post($this->makeInvoice(10_000_000));

        $this->assertSame('BELUM', $inv->status);
        $this->assertNotNull($inv->jurnal_id);
        $this->assertTrue($inv->jurnal->isBalanced());
    }

    public function test_payment_transitions_status_to_sebagian_then_lunas(): void
    {
        $inv = $this->service->post($this->makeInvoice(10_000_000));

        $inv = $this->service->recordPayment($inv, 4_000_000);
        $this->assertSame('SEBAGIAN', $inv->status);
        $this->assertEqualsWithDelta(6_000_000, (float) $inv->sisa, 0.01);

        $inv = $this->service->recordPayment($inv, 6_000_000);
        $this->assertSame('LUNAS', $inv->status);
        $this->assertEqualsWithDelta(0, (float) $inv->sisa, 0.01);
    }

    public function test_adding_denda_posts_a_journal_and_increases_sisa(): void
    {
        $inv = $this->service->post($this->makeInvoice(10_000_000));
        $inv = $this->service->addDenda($inv, 250_000);

        $this->assertEqualsWithDelta(250_000, (float) $inv->denda, 0.01);
        $this->assertEqualsWithDelta(10_250_000, (float) $inv->sisa, 0.01);
    }

    public function test_aging_buckets_classify_by_days_overdue(): void
    {
        // overdue 45 days as of 2026-06-30 → bucket 31-60
        $this->service->post($this->makeInvoice(5_000_000, '2026-05-16'));
        // not yet due
        $this->service->post($this->makeInvoice(3_000_000, '2026-07-31'));

        $buckets = app(ArAgingService::class)->buckets($this->company->id, Carbon::parse('2026-06-30'));

        $this->assertSame(1, $buckets['31-60']['count']);
        $this->assertEqualsWithDelta(5_000_000, $buckets['31-60']['total'], 0.01);
        $this->assertSame(1, $buckets['BELUM_JATUH_TEMPO']['count']);
    }
}
