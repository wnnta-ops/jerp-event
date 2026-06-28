<?php

declare(strict_types=1);

namespace App\Domain\Commission\Actions;

use App\Domain\Commission\Models\CommissionPayout;
use App\Domain\Finance\Memo\Actions\CreateMemo;
use Illuminate\Support\Facades\DB;

final class ApproveCommissionPayout
{
    public function __construct(private readonly CreateMemo $createMemo) {}

    public function execute(CommissionPayout $payout): void
    {
        DB::transaction(function () use ($payout): void {
            // Buat memo pengeluaran
            $memo = $this->createMemo->execute([
                'type'        => 'outgoing',
                'memo_date'   => now()->toDateString(),
                'amount'      => $payout->amount,
                'description' => "Pembayaran komisi #{$payout->id} ke agen {$payout->line?->agent?->name}",
                'reference_type' => 'commission_payout',
                'reference_id'   => $payout->id,
                'status'      => 'approved',
            ]);

            $payout->update([
                'status'  => 'approved',
                'memo_id' => $memo->id,
            ]);
        });
    }
}
