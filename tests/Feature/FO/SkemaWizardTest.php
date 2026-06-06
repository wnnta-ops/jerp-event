<?php

namespace Tests\Feature\FO;

use App\Models\Company;
use App\Models\Project;
use App\Modules\FO\Adminjual\Services\SkemaWizardService;
use Database\Seeders\TenantSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SkemaWizardTest extends TestCase
{
    use RefreshDatabase;

    private SkemaWizardService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TenantSeeder::class);
        $this->svc = new SkemaWizardService;
    }

    public function test_cash_keras_bf_disebar_di_angsuran(): void
    {
        // CASH: BF 3jt + 3 angsuran (total 100%), BF disebar di angsuran.
        $lines = $this->svc->generateLines([
            'bf_count' => 1, 'bf_nilai' => 3_000_000, 'bf_satuan' => 'RP',
            'ang_count' => 3, 'ang_nilai' => 100, 'ang_pertama_basis' => 'BULAN', 'ang_interval_basis' => 'BULAN', 'ang_pertama' => 14, 'ang_interval' => 1,
            'bf_dipotong_di' => 'ANGSURAN_DISEBAR',
        ], 'CASH_KERAS');

        $this->assertCount(4, $lines);                                  // 1 BF + 3 ANG
        $this->assertSame('BF', $lines[0]['tipe']);
        $this->assertEqualsWithDelta(3_000_000, (float) $lines[0]['nominal'], 0.01);

        $ang = array_slice($lines, 1);
        $this->assertSame(100.0, round(array_sum(array_column($ang, 'persen')), 2)); // total tepat 100
        foreach ($ang as $a) {
            $this->assertSame('ANG', $a['tipe']);
            $this->assertTrue($a['flag_bf']);    // BF disebar → semua angsuran flag_bf
            $this->assertFalse($a['flag_kpr']);  // cash → bukan KPR
        }
        $this->assertSame('PELUNASAN', $ang[2]['nama_termin']);
    }

    public function test_kpr_dp_disebar_dan_pencairan_kpr(): void
    {
        // KPR: BF + DP 3 termin (5% total, BF disebar di DP) + 1 pencairan KPR (95%).
        $lines = $this->svc->generateLines([
            'bf_count' => 1, 'bf_nilai' => 3_000_000, 'bf_satuan' => 'RP',
            'dp_count' => 3, 'dp_nilai' => 5, 'dp_pertama_basis' => 'BULAN', 'dp_interval_basis' => 'BULAN', 'dp_pertama' => 1, 'dp_interval' => 1,
            'ang_count' => 1, 'ang_nilai' => 95, 'ang_pertama_basis' => 'BULAN', 'ang_pertama' => 1,
            'bf_dipotong_di' => 'DP_DISEBAR',
        ], 'KPR');

        $this->assertCount(5, $lines); // BF + 3 DP + 1 ANG
        $dp = array_values(array_filter($lines, fn ($l) => $l['tipe'] === 'DP'));
        $this->assertCount(3, $dp);
        foreach ($dp as $d) {
            $this->assertTrue($d['flag_bf']);    // BF disebar di DP
            $this->assertFalse($d['flag_kpr']);
        }
        $ang = array_values(array_filter($lines, fn ($l) => $l['tipe'] === 'ANG'))[0];
        $this->assertSame('PENCAIRAN KPR', $ang['nama_termin']);
        $this->assertTrue($ang['flag_kpr']);     // dicairkan bank
        $this->assertEqualsWithDelta(95, (float) $ang['persen'], 0.01);
    }

    public function test_create_persists_skema_and_lines(): void
    {
        $company = Company::create(['tenant_id' => 1, 'code' => 'MUP', 'name' => 'PT MUP', 'is_active' => true]);
        $project = Project::create(['tenant_id' => 1, 'company_id' => $company->id, 'code' => 'JR', 'name' => 'Java Residence', 'is_active' => true]);

        $skema = $this->svc->create(
            ['name' => 'CASH', 'project_id' => $project->id, 'cara_bayar' => 'CASH_KERAS', 'bf_dipotong_di' => 'ANGSURAN_DISEBAR', 'tampil_siteplan' => true],
            ['bf_count' => 1, 'bf_nilai' => 3_000_000, 'bf_satuan' => 'RP', 'ang_count' => 3, 'ang_nilai' => 100, 'ang_satuan' => 'PERSEN', 'bf_dipotong_di' => 'ANGSURAN_DISEBAR'],
        );

        $this->assertNotEmpty($skema->code);
        $this->assertTrue($skema->tampil_siteplan);
        $this->assertCount(4, $skema->lines);
        $this->assertEqualsWithDelta(3_000_000, (float) $skema->bf_nominal, 0.01); // BF (RP) → bf_nominal
        $this->assertDatabaseHas('fo_skema_lines', ['skema_id' => $skema->id, 'tipe' => 'BF']);
    }
}
