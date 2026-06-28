<?php

declare(strict_types=1);

namespace App\Domain\Tax\Services;

use App\Domain\Sales\Pricing\Models\PaymentScheme;

/**
 * Skema Cara Bayar Calculator.
 *
 * Apply a PaymentScheme ke unit price → breakdown lengkap:
 *   Price List → Discount → After Discount → DPP → PPN → Nilai Kontrak
 *   + DP amount + cicilan + KPR amount.
 *
 * Mirrors Java Core System Lama "Kalkulator Skema Cara Bayar".
 */
final class SkemaCalculator
{
    public const DEFAULT_PPN_PCT = 11.0;

    /**
     * @param array{
     *   price_list: int|float,
     *   discount_pct?: int|float,
     *   additional_discount?: int|float,
     *   ppn_pct?: int|float,
     * } $overrides
     *
     * @return array{
     *   scheme: array{code: string, name: string, type: string},
     *   price_list: float,
     *   discount_pct: float, discount_value: float,
     *   additional_discount: float,
     *   after_discount: float,
     *   dpp: float,
     *   ppn_pct: float, ppn_value: float,
     *   nilai_kontrak: float,
     *   dp_pct: float, dp_amount: float,
     *   sisa_setelah_dp: float,
     *   installment_months: int, installment_per_month: float,
     * }
     */
    public function calculate(PaymentScheme $scheme, array $overrides): array
    {
        $priceList   = (float) ($overrides['price_list'] ?? 0);
        $discountPct = (float) ($overrides['discount_pct'] ?? $scheme->discount_pct ?? 0);
        $additional  = (float) ($overrides['additional_discount'] ?? 0);
        $ppnPct      = (float) ($overrides['ppn_pct'] ?? self::DEFAULT_PPN_PCT);

        $discountValue = $priceList * ($discountPct / 100);
        $afterDiscount = max(0, $priceList - $discountValue - $additional);

        // DPP = harga net sebelum PPN. After discount IS the DPP basis.
        $dpp        = $afterDiscount;
        $ppnValue   = $dpp * ($ppnPct / 100);
        $nilaiKontrak = $dpp + $ppnValue;

        $dpPct    = (float) ($scheme->down_payment_pct ?? 0);
        $dpAmount = $nilaiKontrak * ($dpPct / 100);
        $sisa     = max(0, $nilaiKontrak - $dpAmount);

        $months   = (int) ($scheme->installment_months ?? 0);
        $perMonth = $months > 0 ? $sisa / $months : 0;

        return [
            'scheme' => [
                'code' => $scheme->code,
                'name' => $scheme->name,
                'type' => $scheme->type,
            ],
            'price_list'             => round($priceList, 2),
            'discount_pct'           => round($discountPct, 2),
            'discount_value'         => round($discountValue, 2),
            'additional_discount'    => round($additional, 2),
            'after_discount'         => round($afterDiscount, 2),
            'dpp'                    => round($dpp, 2),
            'ppn_pct'                => round($ppnPct, 2),
            'ppn_value'              => round($ppnValue, 2),
            'nilai_kontrak'          => round($nilaiKontrak, 2),
            'dp_pct'                 => round($dpPct, 2),
            'dp_amount'              => round($dpAmount, 2),
            'sisa_setelah_dp'        => round($sisa, 2),
            'installment_months'     => $months,
            'installment_per_month'  => round($perMonth, 2),
        ];
    }
}
