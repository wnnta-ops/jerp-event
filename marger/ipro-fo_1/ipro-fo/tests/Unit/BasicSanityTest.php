<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Sales\Booking\Enums\BookingStatus;
use App\Domain\Sales\Booking\Services\PricingCalculator;
use App\Exceptions\InvalidStateTransitionException;
use App\Support\ValueObjects\Money;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Quick sanity tests yang tidak butuh database — verify domain logic.
 */
final class BasicSanityTest extends TestCase
{
    #[Test]
    public function money_formats_idr_currency(): void
    {
        $m = Money::fromRupiah(1_500_000);
        $this->assertSame(1_500_000, $m->amount);
        $this->assertSame('IDR', $m->currency);
        $this->assertSame('Rp 1.500.000', (string) $m);
    }

    #[Test]
    public function money_arithmetic_works(): void
    {
        $a = Money::fromRupiah(1_000_000_000);
        $b = Money::fromRupiah(110_000_000);

        $this->assertSame(1_110_000_000, $a->plus($b)->amount);
        $this->assertSame(  890_000_000, $a->minus($b)->amount);
    }

    #[Test]
    public function money_percent_calculates_ppn(): void
    {
        $price = Money::fromRupiah(1_000_000_000);
        $ppn   = $price->percent(11.0);

        $this->assertSame(110_000_000, $ppn->amount);
    }

    #[Test]
    public function pricing_calc_no_discount(): void
    {
        $calc = new PricingCalculator();
        $r = $calc->calculate(basePrice: 1_000_000_000, ppnPct: 11);

        $this->assertSame(1_000_000_000.0, $r['base']);
        $this->assertSame(0.0,              $r['discount']);
        $this->assertSame(110_000_000.0,    $r['ppn']);
        $this->assertSame(1_110_000_000.0,  $r['total']);
    }

    #[Test]
    public function pricing_calc_with_discount(): void
    {
        $calc = new PricingCalculator();
        $r = $calc->calculate(basePrice: 1_000_000_000, discountPct: 10, ppnPct: 11);

        $this->assertSame(100_000_000.0,  $r['discount']);
        $this->assertSame(900_000_000.0,  $r['after_discount']);
        $this->assertSame(99_000_000.0,   $r['ppn']);
        $this->assertSame(999_000_000.0,  $r['total']);
    }

    #[Test]
    public function booking_state_machine_allows_valid_transitions(): void
    {
        $this->assertTrue(BookingStatus::Draft->canTransitionTo(BookingStatus::Nup));
        $this->assertTrue(BookingStatus::PilihUnit->canTransitionTo(BookingStatus::Ppjb));
        $this->assertTrue(BookingStatus::Ppjb->canTransitionTo(BookingStatus::Active));
        $this->assertTrue(BookingStatus::Active->canTransitionTo(BookingStatus::Lunas));
        $this->assertTrue(BookingStatus::Lunas->canTransitionTo(BookingStatus::Closed));
    }

    #[Test]
    public function booking_state_machine_rejects_invalid_transitions(): void
    {
        $this->assertFalse(BookingStatus::Closed->canTransitionTo(BookingStatus::Draft));
        $this->assertFalse(BookingStatus::Cancelled->canTransitionTo(BookingStatus::Active));
    }

    #[Test]
    public function booking_state_machine_throws_on_invalid_transition(): void
    {
        $this->expectException(InvalidStateTransitionException::class);
        BookingStatus::Closed->assertCanTransitionTo(BookingStatus::Draft);
    }

    #[Test]
    public function booking_status_has_label_and_color(): void
    {
        $this->assertSame('Aktif', BookingStatus::Active->label());
        $this->assertSame('success', BookingStatus::Active->color());
        $this->assertSame('Dibatalkan', BookingStatus::Cancelled->label());
        $this->assertSame('danger', BookingStatus::Cancelled->color());
    }

    // ── Phase 6: Calculator tests ────────────────────────

    #[Test]
    public function bphtb_calculator_uses_max_of_ajb_vs_njop(): void
    {
        $calc = new \App\Domain\Tax\Services\BphtbCalculator();
        $r = $calc->calculate([
            'land_area' => 90, 'building_area' => 36,
            'njop_per_m2_land' => 3_000_000, 'njop_per_m2_building' => 4_500_000,
            'ajb_value' => 500_000_000, 'npotkp' => 80_000_000,
        ]);

        // NJOP = 90*3jt + 36*4.5jt = 270jt + 162jt = 432jt
        $this->assertSame(432_000_000.0, $r['total_njop']);
        // NPOP = max(500jt AJB, 432jt NJOP) = 500jt
        $this->assertSame(500_000_000.0, $r['npop']);
        // NPOPKP = 500jt - 80jt = 420jt
        $this->assertSame(420_000_000.0, $r['npopkp']);
        // BPHTB = 420jt * 5% = 21jt
        $this->assertSame(21_000_000.0, $r['bphtb']);
    }

    #[Test]
    public function bphtb_calculator_clamps_negative_npopkp_to_zero(): void
    {
        $calc = new \App\Domain\Tax\Services\BphtbCalculator();
        $r = $calc->calculate([
            'land_area' => 10, 'building_area' => 10,
            'njop_per_m2_land' => 1_000_000, 'njop_per_m2_building' => 1_000_000,
            'ajb_value' => 20_000_000, 'npotkp' => 80_000_000,
        ]);

        // NPOP = 20jt, NPOTKP = 80jt → NPOPKP clamped to 0
        $this->assertSame(0.0, $r['npopkp']);
        $this->assertSame(0.0, $r['bphtb']);
    }

    #[Test]
    public function kpr_calculator_computes_amortization(): void
    {
        $calc = new \App\Domain\Tax\Services\KprCalculator();
        $r = $calc->calculate(['loan' => 500_000_000, 'tenor_months' => 240, 'annual_rate' => 7.5]);

        // Known annuity result ~ Rp 4,027,966 / month
        $this->assertEqualsWithDelta(4_027_966, $r['monthly_installment'], 5);
        $this->assertSame(240, $r['tenor_months']);
        // Total interest must be positive
        $this->assertGreaterThan(0, $r['total_interest']);
        // 12 months preview
        $this->assertCount(12, $r['schedule_preview']);
    }

    #[Test]
    public function kpr_calculator_handles_zero_interest(): void
    {
        $calc = new \App\Domain\Tax\Services\KprCalculator();
        $r = $calc->calculate(['loan' => 120_000_000, 'tenor_months' => 12, 'annual_rate' => 0]);

        // 0% interest → 120jt / 12 = 10jt per month
        $this->assertSame(10_000_000.0, $r['monthly_installment']);
        $this->assertSame(0.0, $r['total_interest']);
    }
}
