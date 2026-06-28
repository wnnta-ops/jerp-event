<?php

declare(strict_types=1);

namespace App\Domain\Tax\Services;

/**
 * KPR Amortization Calculator (Annuity / Bunga Efektif).
 *
 *   M = P × [i × (1+i)^n] / [(1+i)^n − 1]
 *
 *   P = pokok pinjaman (loan)
 *   i = bunga bulanan = annual_rate / 12 / 100
 *   n = tenor dalam bulan
 *   M = cicilan bulanan
 *
 * Returns monthly installment + total payment + total interest + schedule preview (12 month).
 */
final class KprCalculator
{
    /**
     * @param array{loan: int|float, tenor_months: int, annual_rate: int|float} $input
     *
     * @return array{
     *   monthly_installment: float,
     *   total_payment: float,
     *   total_interest: float,
     *   loan: float, tenor_months: int, annual_rate: float, monthly_rate: float,
     *   schedule_preview: array<int, array{month: int, principal: float, interest: float, balance: float}>
     * }
     */
    public function calculate(array $input): array
    {
        $loan        = (float) ($input['loan'] ?? 0);
        $tenorMonths = (int)   ($input['tenor_months'] ?? 0);
        $annualRate  = (float) ($input['annual_rate'] ?? 0);

        if ($loan <= 0 || $tenorMonths <= 0) {
            return [
                'monthly_installment' => 0,
                'total_payment'       => 0,
                'total_interest'      => 0,
                'loan'                => $loan,
                'tenor_months'        => $tenorMonths,
                'annual_rate'         => $annualRate,
                'monthly_rate'        => 0,
                'schedule_preview'    => [],
            ];
        }

        $monthlyRate = $annualRate / 12 / 100;

        if ($monthlyRate <= 0) {
            // 0% interest case
            $monthly = $loan / $tenorMonths;
        } else {
            $factor  = pow(1 + $monthlyRate, $tenorMonths);
            $monthly = $loan * ($monthlyRate * $factor) / ($factor - 1);
        }

        $totalPayment  = $monthly * $tenorMonths;
        $totalInterest = $totalPayment - $loan;

        // Schedule preview — first 12 months
        $balance  = $loan;
        $preview  = [];
        $previewN = min(12, $tenorMonths);
        for ($m = 1; $m <= $previewN; $m++) {
            $interestM   = $balance * $monthlyRate;
            $principalM  = $monthly - $interestM;
            $balance    -= $principalM;
            $preview[] = [
                'month'     => $m,
                'principal' => round($principalM, 2),
                'interest'  => round($interestM, 2),
                'balance'   => round(max(0, $balance), 2),
            ];
        }

        return [
            'monthly_installment' => round($monthly, 2),
            'total_payment'       => round($totalPayment, 2),
            'total_interest'      => round($totalInterest, 2),
            'loan'                => $loan,
            'tenor_months'        => $tenorMonths,
            'annual_rate'         => $annualRate,
            'monthly_rate'        => round($monthlyRate, 8),
            'schedule_preview'    => $preview,
        ];
    }
}
