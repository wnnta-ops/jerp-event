<?php

declare(strict_types=1);

namespace App\Domain\Sales\Booking\Services;

/**
 * Pure calculator (no DB) — gunakan di Action, FormRequest preview, dan test.
 */
final class PricingCalculator
{
    /**
     * @return array{base:float,discount:float,after_discount:float,gimmick:float,ppn:float,total:float}
     */
    public function calculate(
        float $basePrice,
        float $discountPct = 0,
        float $discountAmount = 0,
        float $gimmickValue = 0,
        float $ppnPct = 11.0,
    ): array {
        $discount = $discountAmount > 0
            ? $discountAmount
            : round($basePrice * ($discountPct / 100), 2);

        $afterDiscount = max(0.0, $basePrice - $discount);

        // Gimmick adalah "bonus" yang mengurangi nilai jual untuk perhitungan PPN.
        $taxable = max(0.0, $afterDiscount - $gimmickValue);
        $ppn     = round($taxable * ($ppnPct / 100), 2);
        $total   = round($afterDiscount + $ppn, 2);

        return [
            'base'           => $basePrice,
            'discount'       => $discount,
            'after_discount' => $afterDiscount,
            'gimmick'        => $gimmickValue,
            'ppn'            => $ppn,
            'total'          => $total,
        ];
    }
}
