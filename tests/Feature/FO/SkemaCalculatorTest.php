<?php

namespace Tests\Feature\FO;

use App\Modules\FO\Adminjual\Services\SkemaCalculatorService;
use Tests\TestCase;

/**
 * Verifikasi rumus kalkulator skema cocok angka contoh Gambar 2 (harga 350jt, UTJ 3jt).
 * compute() murni (tanpa DB) sehingga aman dari urutan/koneksi SQLite.
 */
class SkemaCalculatorTest extends TestCase
{
    private SkemaCalculatorService $calc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calc = new SkemaCalculatorService;
    }

    /** Cari satu baris breakdown berdasarkan label (prefix). */
    private function line(array $out, string $labelPrefix): array
    {
        foreach ($out['lines'] as $l) {
            if (str_starts_with($l['label'], $labelPrefix)) {
                return $l;
            }
        }

        return ['nominal' => null];
    }

    public function test_dp_disebar_kpr_dp3x(): void
    {
        // DP DISEBAR: harga 350jt, DP 5%, subsidi 20%, UTJ 3jt, 3x DP.
        $out = $this->calc->compute([
            'mode' => 'DP_DISEBAR', 'cara_bayar' => 'KPR',
            'utj' => 3_000_000, 'dp_persen' => 5, 'subsidi_persen' => 20, 'n_dp' => 3,
        ], 350_000_000);

        $this->assertEqualsWithDelta(3_000_000, $this->line($out, 'UTJ')['nominal'], 0.01);          // E = UTJ
        $this->assertEqualsWithDelta(3_500_000, $this->line($out, 'Subsidi')['nominal'], 0.01);       // C = B×20%
        $this->assertEqualsWithDelta(3_666_666.67, $this->line($out, 'DP 1')['nominal'], 0.01);       // G = F/3
        $this->assertEqualsWithDelta(339_000_000, $out['plafond_kpr'], 0.01);                         // H = A−F
        $this->assertEqualsWithDelta(14_000_000, $out['total_customer'], 0.01);                       // UTJ + DP3X(11jt)
    }

    public function test_angsuran_disebar_cash_3x(): void
    {
        // ANGSURAN DISEBAR (CASH): (350jt − 3jt UTJ) / 3 = 115.666.666,67.
        $out = $this->calc->compute([
            'mode' => 'ANGSURAN_DISEBAR', 'cara_bayar' => 'CASH_KERAS', 'utj' => 3_000_000, 'n_ang' => 3,
        ], 350_000_000);

        $this->assertEqualsWithDelta(115_666_666.67, $this->line($out, 'ANGSURAN 1')['nominal'], 0.01);
        $this->assertEqualsWithDelta(0, $out['plafond_kpr'], 0.01);
        $this->assertEqualsWithDelta(350_000_000, $out['total_customer'], 0.01);
    }

    public function test_angsuran_disebar_inhouse_6x(): void
    {
        // INHOUSE (CASH BERTAHAP): (350jt − 3jt) / 6 = 57.833.333,33.
        $out = $this->calc->compute([
            'mode' => 'ANGSURAN_DISEBAR', 'cara_bayar' => 'CASH_BERTAHAP', 'utj' => 3_000_000, 'n_ang' => 6,
        ], 350_000_000);

        $this->assertEqualsWithDelta(57_833_333.33, $this->line($out, 'ANGSURAN 1')['nominal'], 0.01);
    }

    public function test_angsuran1_free_dp(): void
    {
        // ANGSURAN 1 (FREE DP): plafond = 350jt − DP 5%(17,5jt) − UTJ 3jt = 329,5jt.
        $out = $this->calc->compute([
            'mode' => 'ANGSURAN1', 'cara_bayar' => 'KPR', 'utj' => 3_000_000, 'dp_persen' => 5,
        ], 350_000_000);

        $this->assertEqualsWithDelta(17_500_000, $this->line($out, 'DP')['nominal'], 0.01);     // DP free
        $this->assertEqualsWithDelta(329_500_000, $out['plafond_kpr'], 0.01);
        $this->assertEqualsWithDelta(3_000_000, $out['total_customer'], 0.01);                  // customer bayar UTJ saja
    }
}
