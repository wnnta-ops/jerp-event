<?php

namespace Tests\Feature\BO;

use App\Models\Company;
use App\Modules\BO\GeneralLedger\Contracts\JurnalPostingServiceInterface;
use App\Modules\BO\GeneralLedger\Contracts\PostsToGeneralLedger;
use App\Modules\BO\GeneralLedger\DataTransferObjects\JurnalLineDTO;
use App\Modules\BO\GeneralLedger\Exceptions\ClosedPeriodException;
use App\Modules\BO\GeneralLedger\Exceptions\UnbalancedJournalException;
use App\Modules\BO\GeneralLedger\Models\Period;
use App\Services\Numerator\NumeratorServiceInterface;
use Database\Seeders\BoChartOfAccountSeeder;
use Database\Seeders\BoNumeratorSeeder;
use Database\Seeders\TenantSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GeneralLedgerTest extends TestCase
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

    public function test_posts_a_balanced_manual_journal(): void
    {
        $jurnal = $this->posting->postManual(
            [
                'tgl_jurnal' => '2026-05-31',
                'company_id' => $this->company->id,
                'jenis_dokumen' => 'JU',
                'keterangan' => 'Setoran modal',
            ],
            [
                ['kode_account' => '1.1.01.101', 'debet' => 1000000, 'kredit' => 0],
                ['kode_account' => '3.1.01.001', 'debet' => 0, 'kredit' => 1000000],
            ]
        );

        $this->assertNotEmpty($jurnal->no_jurnal);
        $this->assertSame('DRAFT', $jurnal->status);
        $this->assertCount(2, $jurnal->detil);
        $this->assertSame('1000000.00', (string) $jurnal->total_debet);
        $this->assertTrue($jurnal->isBalanced());
    }

    public function test_rejects_an_unbalanced_journal(): void
    {
        $this->expectException(UnbalancedJournalException::class);

        $this->posting->postManual(
            ['tgl_jurnal' => '2026-05-31', 'company_id' => $this->company->id],
            [
                ['kode_account' => '1.1.01.101', 'debet' => 1000000, 'kredit' => 0],
                ['kode_account' => '3.1.01.001', 'debet' => 0, 'kredit' => 999999],
            ]
        );
    }

    public function test_generates_sequential_atomic_journal_numbers(): void
    {
        $numerator = app(NumeratorServiceInterface::class);
        $a = $numerator->next('JU', $this->company->id);
        $b = $numerator->next('JU', $this->company->id);

        $this->assertNotSame($a, $b);
        $this->assertMatchesRegularExpression('/^JU-\d{6}-\d{5}$/', $a);
    }

    public function test_posts_a_journal_from_a_source_document(): void
    {
        $source = $this->fakeSource(750000);
        $jurnal = $this->posting->post($source);

        $this->assertSame('POSTED', $jurnal->status);
        $this->assertTrue($jurnal->posted);
        $this->assertSame('BKM', $jurnal->jenis_dokumen);
        $this->assertSame('BKM-TEST-1', $jurnal->no_dokumen);
        $this->assertTrue($jurnal->isBalanced());
    }

    public function test_reverses_a_posted_journal(): void
    {
        $jurnal = $this->posting->post($this->fakeSource(500000));
        $reversal = $this->posting->reverse($jurnal, 'koreksi');

        $this->assertSame($jurnal->id, $reversal->reversal_of_id);
        $this->assertSame((string) $jurnal->total_debet, (string) $reversal->total_kredit);
        // Original debit line becomes a credit on the reversal.
        $origDebetLine = $jurnal->detil->firstWhere('debet', '>', 0);
        $revLine = $reversal->detil->firstWhere('kode_account', $origDebetLine->kode_account);
        $this->assertEqualsWithDelta((float) $origDebetLine->debet, (float) $revLine->kredit, 0.001);
    }

    public function test_blocks_posting_in_a_closed_period(): void
    {
        Period::create([
            'company_id' => $this->company->id,
            'tahun' => 2026,
            'bulan' => 5,
            'periode' => '2026-05',
            'status' => 'CLOSED',
        ]);

        $this->expectException(ClosedPeriodException::class);
        $this->posting->post($this->fakeSource(100000, '2026-05-15'));
    }

    private function fakeSource(float $amount, string $date = '2026-05-31'): PostsToGeneralLedger
    {
        return new class($amount, $date, $this->company->id) implements PostsToGeneralLedger
        {
            public function __construct(
                private float $amount,
                private string $date,
                private int $companyId,
            ) {}

            public function glLines(): array
            {
                return [
                    JurnalLineDTO::debet('1.1.01.101', $this->amount, 'Penerimaan'),
                    JurnalLineDTO::kredit('1.1.02.001', $this->amount, 'Pelunasan piutang'),
                ];
            }

            public function glDocumentType(): string
            {
                return 'BKM';
            }

            public function glDocumentNo(): string
            {
                return 'BKM-TEST-1';
            }

            public function glCompanyId(): int
            {
                return $this->companyId;
            }

            public function glDate(): \DateTimeInterface
            {
                return new \DateTimeImmutable($this->date);
            }

            public function glDescription(): ?string
            {
                return 'Pembayaran customer';
            }
        };
    }
}
