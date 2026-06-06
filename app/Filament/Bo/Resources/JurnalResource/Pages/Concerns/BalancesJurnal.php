<?php

namespace App\Filament\Bo\Resources\JurnalResource\Pages\Concerns;

use App\Services\Numerator\NumeratorServiceInterface;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * Shared header/line normalisation for manual journal create & edit pages:
 * sequential `urutan`, derived totals/periode, balance enforcement, and
 * (on create) an atomic journal number.
 */
trait BalancesJurnal
{
    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    protected function normaliseJurnal(array $data, bool $isCreate): array
    {
        $rows = $data['detil'] ?? [];

        $totalDebet = 0.0;
        $totalKredit = 0.0;
        $urutan = 1;
        foreach ($rows as $i => $row) {
            $data['detil'][$i]['urutan'] = $urutan++;
            $totalDebet += (float) ($row['debet'] ?? 0);
            $totalKredit += (float) ($row['kredit'] ?? 0);
        }

        if (abs($totalDebet - $totalKredit) >= 0.001 || $totalDebet <= 0) {
            Notification::make()
                ->danger()
                ->title('Jurnal tidak balance')
                ->body(sprintf('Total debet %s ≠ total kredit %s.', number_format($totalDebet, 2), number_format($totalKredit, 2)))
                ->send();
            $this->halt();
        }

        $date = Carbon::parse($data['tgl_jurnal']);
        $data['periode'] = $date->format('Y-m');
        $data['total_debet'] = round($totalDebet, 2);
        $data['total_kredit'] = round($totalKredit, 2);

        if ($isCreate) {
            $data['no_jurnal'] = app(NumeratorServiceInterface::class)->next('JU', (int) $data['company_id']);
            $data['status'] = 'DRAFT';
            $data['posted'] = false;
        }

        return $data;
    }
}
