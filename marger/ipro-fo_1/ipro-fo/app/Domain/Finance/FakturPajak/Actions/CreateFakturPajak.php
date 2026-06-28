<?php

declare(strict_types=1);

namespace App\Domain\Finance\FakturPajak\Actions;

use App\Domain\Finance\FakturPajak\Models\FakturPajak;
use App\Domain\Foundation\Numerator\Services\NumeratorService;
use Illuminate\Support\Facades\DB;

final class CreateFakturPajak
{
    public function __construct(private readonly NumeratorService $numerator) {}

    public function execute(array $data, array $items): FakturPajak
    {
        return DB::transaction(function () use ($data, $items): FakturPajak {
            $dpp   = collect($items)->sum('total');
            $ppn   = round($dpp * ((float) ($data['ppn_pct'] ?? config('domain.tax.ppn_default_pct')) / 100), 2);
            $total = $dpp + $ppn;

            $fp = FakturPajak::create([
                'fp_number'   => $this->numerator->next('FP'),
                'customer_id' => $data['customer_id'],
                'booking_id'  => $data['booking_id'] ?? null,
                'issued_at'   => $data['issued_at'],
                'dpp'         => $dpp,
                'ppn'         => $ppn,
                'ppnbm'       => 0,
                'total'       => $total,
                'status'      => 'issued',
            ]);

            foreach ($items as $i) {
                $fp->items()->create([
                    'description' => $i['description'],
                    'qty'         => $i['qty']        ?? 1,
                    'unit_price'  => $i['unit_price'],
                    'total'       => $i['total'],
                ]);
            }

            return $fp;
        });
    }
}
