<?php

declare(strict_types=1);

namespace App\Domain\Sales\Nup\Actions;

use App\Domain\Sales\Nup\Models\NupPayment;
use App\Domain\Sales\Nup\Models\NupRegistration;
use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

final class RefundNup
{
    /**
     * @return array{refund_amount: float, penalty: float}
     */
    public function execute(NupRegistration $reg, ?string $reason = null): array
    {
        if ($reg->status !== 'paid' && $reg->status !== 'active') {
            throw new BusinessRuleException(
                "NUP status {$reg->status} tidak dapat di-refund.",
                errorCode: 'NUP_NOT_REFUNDABLE',
            );
        }

        if (! $reg->scheme->is_refundable) {
            throw new BusinessRuleException('Skema NUP ini tidak refundable.', errorCode: 'NUP_NON_REFUNDABLE');
        }

        return DB::transaction(function () use ($reg, $reason): array {
            $totalPaid     = (float) NupPayment::where('nup_registration_id', $reg->id)->sum('amount');
            $penaltyPct    = (float) $reg->scheme->refund_penalty_pct;
            $penaltyAmount = round($totalPaid * ($penaltyPct / 100), 2);
            $refundAmount  = $totalPaid - $penaltyAmount;

            DB::table('refunds')->insert([
                'tenant_id'        => $reg->tenant_id,
                'refund_number'    => 'REF-NUP-'.$reg->id.'-'.now()->format('ymdHis'),
                'refundable_type'  => 'nup_registration',
                'refundable_id'    => $reg->id,
                'amount'           => $refundAmount,
                'requested_at'     => now()->toDateString(),
                'status'           => 'pending',
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);

            $reg->update(['status' => 'refunded']);

            return ['refund_amount' => $refundAmount, 'penalty' => $penaltyAmount];
        });
    }
}
