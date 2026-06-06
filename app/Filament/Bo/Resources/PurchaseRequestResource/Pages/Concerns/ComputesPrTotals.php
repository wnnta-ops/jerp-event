<?php

namespace App\Filament\Bo\Resources\PurchaseRequestResource\Pages\Concerns;

trait ComputesPrTotals
{
    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    protected function computeTotals(array $data): array
    {
        $rows = $data['lines'] ?? [];
        $total = 0.0;
        $urutan = 1;
        foreach ($rows as $i => $row) {
            $subtotal = (float) ($row['qty'] ?? 0) * (float) ($row['harga_estimasi'] ?? 0);
            $data['lines'][$i]['subtotal'] = round($subtotal, 2);
            $data['lines'][$i]['urutan'] = $urutan++;
            $total += $subtotal;
        }
        $data['estimasi_total'] = round($total, 2);

        return $data;
    }
}
