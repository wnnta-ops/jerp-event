<?php

namespace App\Modules\BO\AccountsReceivable\Services;

use App\Modules\BO\AccountsReceivable\Models\ArInvoice;
use Illuminate\Support\Carbon;

/**
 * Buckets outstanding receivables by overdue age. Buckets:
 * 0–30, 31–60, 61–90, >90 days past due (current = not yet due).
 */
class ArAgingService
{
    /**
     * @return array<string,array{count:int,total:float}>
     */
    public function buckets(?int $companyId = null, ?Carbon $asOf = null): array
    {
        $asOf = $asOf ?? Carbon::today();

        $buckets = [
            'BELUM_JATUH_TEMPO' => ['count' => 0, 'total' => 0.0],
            '1-30' => ['count' => 0, 'total' => 0.0],
            '31-60' => ['count' => 0, 'total' => 0.0],
            '61-90' => ['count' => 0, 'total' => 0.0],
            '>90' => ['count' => 0, 'total' => 0.0],
        ];

        ArInvoice::query()
            ->whereIn('status', ['BELUM', 'SEBAGIAN'])
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->get()
            ->each(function (ArInvoice $inv) use (&$buckets, $asOf) {
                $sisa = (float) $inv->sisa;
                $days = $inv->jatuh_tempo->lt($asOf) ? $inv->jatuh_tempo->diffInDays($asOf) : -1;

                $key = match (true) {
                    $days < 0 => 'BELUM_JATUH_TEMPO',
                    $days <= 30 => '1-30',
                    $days <= 60 => '31-60',
                    $days <= 90 => '61-90',
                    default => '>90',
                };

                $buckets[$key]['count']++;
                $buckets[$key]['total'] += $sisa;
            });

        return $buckets;
    }

    public function totalOutstanding(?int $companyId = null): float
    {
        return (float) ArInvoice::query()
            ->whereIn('status', ['BELUM', 'SEBAGIAN'])
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->sum('sisa');
    }
}
