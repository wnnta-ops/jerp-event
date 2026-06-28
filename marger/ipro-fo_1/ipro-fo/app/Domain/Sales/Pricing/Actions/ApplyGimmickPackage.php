<?php

declare(strict_types=1);

namespace App\Domain\Sales\Pricing\Actions;

use App\Domain\Sales\Contract\Models\Contract;
use App\Domain\Sales\Pricing\Models\ContractGimmick;
use App\Domain\Sales\Pricing\Models\GimmickPackage;
use Illuminate\Support\Facades\DB;

/**
 * Apply gimmick package ke kontrak.
 *
 *  - Replace semua existing gimmicks (kalau $replaceExisting=true)
 *  - Untuk setiap item dalam package: create ContractGimmick dengan
 *    qty=qty_default, harga_satuan=gimmick.harga_satuan, total_harga=qty*harga
 *  - target_lunas dihitung dari tanggal kontrak + target_lunas_days
 *
 * @return \Illuminate\Support\Collection<int, ContractGimmick>
 */
final class ApplyGimmickPackage
{
    public function execute(Contract $contract, GimmickPackage $package, bool $replaceExisting = false): \Illuminate\Support\Collection
    {
        return DB::transaction(function () use ($contract, $package, $replaceExisting) {
            if ($replaceExisting) {
                $contract->gimmicks()->delete();
            }

            $package->load('items');
            $created = collect();

            foreach ($package->items as $item) {
                $qty         = (int) ($item->pivot->qty_default ?? 1);
                $hargaSatuan = (float) $item->harga_satuan;
                $totalHarga  = $qty * $hargaSatuan;

                $targetLunas = null;
                if ($item->pivot->target_lunas_days) {
                    $base = $contract->ppjb_date ?? $contract->signed_at ?? now()->toDateString();
                    $targetLunas = \Carbon\Carbon::parse($base)->addDays((int) $item->pivot->target_lunas_days)->toDateString();
                }

                $cg = ContractGimmick::create([
                    'contract_id'        => $contract->id,
                    'gimmick_id'         => $item->id,
                    'gimmick_package_id' => $package->id,
                    'value'              => $totalHarga, // backward compat with old `value` column
                    'qty'                => $qty,
                    'harga_satuan'       => $hargaSatuan,
                    'total_harga'        => $totalHarga,
                    'target_lunas'       => $targetLunas,
                    'diberikan'          => true,
                    'diterima'           => false,
                    'created_by'         => auth()->id(),
                ]);

                $created->push($cg);
            }

            return $created;
        });
    }
}
