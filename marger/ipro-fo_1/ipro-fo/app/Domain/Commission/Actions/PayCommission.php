<?php

declare(strict_types=1);

namespace App\Domain\Commission\Actions;

use App\Domain\Commission\Models\CommissionPayout;
use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

final class PayCommission
{
    public function execute(CommissionPayout $payout): void
    {
        if ($payout->status !== 'approved') {
            throw new BusinessRuleException('Payout harus APPROVED dulu sebelum dibayar.', errorCode: 'NOT_APPROVED');
        }

        DB::transaction(function () use ($payout): void {
            $payout->update([
                'status'  => 'paid',
                'paid_at' => now()->toDateString(),
                'paid_by' => auth()->id(),
            ]);

            $payout->line?->update(['status' => 'paid']);
        });
    }
}
