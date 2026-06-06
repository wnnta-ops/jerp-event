<?php

namespace App\Modules\BO\GeneralLedger\Services;

use App\Modules\BO\GeneralLedger\Models\JurnalHeader;
use App\Modules\BO\GeneralLedger\Models\Period;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

class PeriodClosingService
{
    /**
     * Close an accounting period. All journals in the period must be POSTED
     * (no dangling DRAFT/APPROVED). Once closed, JurnalPostingService rejects
     * any new posting/edit dated in that period (via PeriodGuard).
     *
     * @param  string  $periode  'YYYY-MM'
     */
    public function close(int $companyId, string $periode): Period
    {
        $dangling = JurnalHeader::query()
            ->where('company_id', $companyId)
            ->where('periode', $periode)
            ->whereIn('status', ['DRAFT', 'APPROVED'])
            ->count();

        if ($dangling > 0) {
            throw new RuntimeException(
                "Tidak bisa tutup periode {$periode}: masih ada {$dangling} jurnal belum POSTED."
            );
        }

        $existing = Period::query()
            ->where('company_id', $companyId)
            ->where('periode', $periode)
            ->first();

        if ($existing && $existing->isClosed()) {
            throw new RuntimeException("Periode {$periode} sudah ditutup.");
        }

        $date = Carbon::parse($periode.'-01');

        $period = $existing ?? new Period([
            'company_id' => $companyId,
            'tahun' => (int) $date->format('Y'),
            'bulan' => (int) $date->format('m'),
            'periode' => $periode,
        ]);

        $period->fill([
            'status' => 'CLOSED',
            'closed_by' => Auth::id(),
            'closed_at' => now(),
        ])->save();

        return $period->refresh();
    }

    public function reopen(int $companyId, string $periode): Period
    {
        $period = Period::query()
            ->where('company_id', $companyId)
            ->where('periode', $periode)
            ->firstOrFail();

        $period->update(['status' => 'OPEN', 'closed_by' => null, 'closed_at' => null]);

        return $period->refresh();
    }
}
