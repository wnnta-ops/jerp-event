<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Services;

use App\Domain\BusinessSupport\Models\BsPurchaseOrderLine;
use App\Domain\BusinessSupport\Models\BsStockMovement;
use App\Domain\BusinessSupport\Models\BsStockTransaction;
use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

/**
 * Inti pergerakan stok (Kartu Stok) — operasional, tanpa jurnal GL (menyusul).
 */
final class StockService
{
    /** Nomor dokumen: PREFIX/JRES/ym/0001 (urut per tahun). */
    public function nextNumber(string $prefix, string $modelClass, string $dateColumn): string
    {
        return app(\App\Domain\BusinessSupport\Services\BoNumberService::class)
            ->next($prefix, null, [], fn () => $this->nextNumberLama($prefix, $modelClass, $dateColumn));
    }

    /** Cara lama — dipakai bila master numerator tidak bisa dipakai. */
    private function nextNumberLama(string $prefix, string $modelClass, string $dateColumn): string
    {
        $year = now()->year;
        $count = $modelClass::withTrashed()->whereYear($dateColumn, $year)->count() + 1;

        return sprintf('%s/JRES/%s/%04d', $prefix, now()->format('y').now()->format('m'), $count);
    }

    /** Saldo stok item pada sebuah gudang. */
    public function balance(int $itemId, int $warehouseId): float
    {
        return (float) BsStockMovement::where('item_id', $itemId)
            ->where('warehouse_id', $warehouseId)
            ->selectRaw("COALESCE(SUM(CASE WHEN direction='in' THEN qty ELSE -qty END),0) as b")
            ->value('b');
    }

    /**
     * Post transaksi stok ke ledger pergerakan.
     * receipt → stok masuk (in); issue → stok keluar (out, divalidasi ketersediaan).
     */
    public function post(BsStockTransaction $trx): void
    {
        $trx->loadMissing('lines');
        $dir = $trx->trx_type === 'receipt' ? 'in' : 'out';

        DB::transaction(function () use ($trx, $dir): void {
            foreach ($trx->lines as $line) {
                $bal = $this->balance($line->item_id, $trx->warehouse_id);

                if ($dir === 'out' && $line->qty > $bal) {
                    throw new BusinessRuleException(
                        "Stok item tidak cukup (tersedia {$bal}, diminta {$line->qty}).",
                        errorCode: 'STOCK_INSUFFICIENT',
                    );
                }

                $newBal = $dir === 'in' ? $bal + (float) $line->qty : $bal - (float) $line->qty;

                BsStockMovement::create([
                    'item_id'       => $line->item_id,
                    'warehouse_id'  => $trx->warehouse_id,
                    'moved_at'      => $trx->trx_date,
                    'direction'     => $dir,
                    'qty'           => $line->qty,
                    'unit_price'    => $line->unit_price,
                    'balance_after' => $newBal,
                    'ref_type'      => 'stock_transaction',
                    'ref_id'        => $trx->id,
                    'ref_number'    => $trx->trx_number,
                ]);

                // Penerimaan dari PO → update qty diterima pada baris PO.
                if ($trx->trx_type === 'receipt' && $trx->purchase_order_id) {
                    BsPurchaseOrderLine::where('purchase_order_id', $trx->purchase_order_id)
                        ->where('item_id', $line->item_id)
                        ->increment('qty_received', (float) $line->qty);
                }
            }
        });
    }
}
