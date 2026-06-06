<?php

namespace App\Modules\BO\GeneralLedger\Services;

use App\Modules\BO\GeneralLedger\Exceptions\ClosedPeriodException;
use App\Modules\BO\GeneralLedger\Models\Period;

class PeriodGuard
{
    /**
     * @param  string  $periode  'YYYY-MM'
     *
     * @throws ClosedPeriodException
     */
    public function assertOpen(int $companyId, string $periode): void
    {
        $period = Period::query()
            ->where('company_id', $companyId)
            ->where('periode', $periode)
            ->first();

        if ($period !== null && $period->isClosed()) {
            throw ClosedPeriodException::for($periode);
        }
    }

    public function isClosed(int $companyId, string $periode): bool
    {
        return Period::query()
            ->where('company_id', $companyId)
            ->where('periode', $periode)
            ->where('status', 'CLOSED')
            ->exists();
    }
}
