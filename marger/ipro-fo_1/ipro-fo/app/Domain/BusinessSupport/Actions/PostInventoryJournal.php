<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Actions;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsJournal;
use App\Domain\BusinessSupport\Models\BsStockMovement;
use App\Domain\BusinessSupport\Models\BsStockTransaction;
use App\Domain\BusinessSupport\Services\JournalService;
use Illuminate\Support\Facades\Log;

/**
 * Jurnal otomatis untuk transaksi persediaan → General Ledger.
 *  - Penerimaan (receipt) : Dr Persediaan  / Cr Hutang Usaha
 *  - Pengeluaran (issue)  : Dr Beban       / Cr Persediaan (pakai harga rata-rata)
 *
 * Best-effort: bila akun GL belum di-set, dilewati (transaksi stok tetap berhasil).
 */
final class PostInventoryJournal
{
    public function __construct(private readonly JournalService $journal) {}

    public function execute(BsStockTransaction $trx): ?BsJournal
    {
        $trx->loadMissing('lines');

        $inventory = $this->acc('inventory');
        if ($inventory === null) {
            return null; // akun persediaan belum di-set
        }

        if ($trx->trx_type === 'receipt') {
            $value = $trx->lines->sum(fn ($l) => (float) $l->qty * (float) $l->unit_price);
            $payable = $this->acc('payable');
            if ($value <= 0 || $payable === null) {
                return null;
            }
            return $this->safePost($trx, [
                ['account_id' => $inventory, 'debit' => $value, 'credit' => 0, 'description' => 'Persediaan masuk'],
                ['account_id' => $payable, 'debit' => 0, 'credit' => $value, 'description' => 'Hutang supplier'],
            ], "Penerimaan barang {$trx->trx_number}");
        }

        // issue: nilai pakai harga rata-rata persediaan per item
        $value = 0.0;
        foreach ($trx->lines as $l) {
            $value += (float) $l->qty * $this->avgCost($l->item_id);
        }
        $expense = $this->acc('expense');
        if ($value <= 0 || $expense === null) {
            return null;
        }
        return $this->safePost($trx, [
            ['account_id' => $expense, 'debit' => round($value, 2), 'credit' => 0, 'description' => 'Pemakaian barang'],
            ['account_id' => $inventory, 'debit' => 0, 'credit' => round($value, 2), 'description' => 'Persediaan keluar'],
        ], "Pengeluaran barang {$trx->trx_number}");
    }

    private function safePost(BsStockTransaction $trx, array $lines, string $desc): ?BsJournal
    {
        try {
            return $this->journal->post([
                'journal_date' => $trx->trx_date->toDateString(),
                'source'       => 'inventory',
                'reference'    => $trx->trx_number,
                'description'  => $desc,
                'created_by'   => $trx->created_by,
            ], $lines);
        } catch (\Throwable $e) {
            Log::warning("Auto-journal persediaan gagal untuk {$trx->trx_number}: {$e->getMessage()}");
            return null;
        }
    }

    private function acc(string $key): ?int
    {
        $code = config("business_support.gl_accounts.$key");

        return $code ? BsAccount::where('code', $code)->value('id') : null;
    }

    /** Harga rata-rata persediaan item dari pergerakan masuk. */
    private function avgCost(int $itemId): float
    {
        $in = BsStockMovement::where('item_id', $itemId)->where('direction', 'in')
            ->selectRaw('COALESCE(SUM(qty),0) q, COALESCE(SUM(qty*unit_price),0) v')->first();

        return ($in && (float) $in->q > 0) ? (float) $in->v / (float) $in->q : 0.0;
    }
}
