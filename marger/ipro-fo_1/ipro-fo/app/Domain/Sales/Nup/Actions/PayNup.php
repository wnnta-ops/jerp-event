<?php

declare(strict_types=1);

namespace App\Domain\Sales\Nup\Actions;

use App\Domain\Sales\Nup\Models\NupPayment;
use App\Domain\Sales\Nup\Models\NupRegistration;
use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

final class PayNup
{
    public function execute(NupRegistration $reg, float $amount, string $method, string $paidAt, ?string $referenceNo = null): NupPayment
    {
        if (! in_array($reg->status, ['active', 'paid'], true)) {
            throw new BusinessRuleException("NUP status {$reg->status} tidak menerima pembayaran.", errorCode: 'NUP_NOT_PAYABLE');
        }

        return DB::transaction(function () use ($reg, $amount, $method, $paidAt, $referenceNo): NupPayment {
            $payment = NupPayment::create([
                'nup_registration_id' => $reg->id,
                'amount'              => $amount,
                'paid_at'             => $paidAt,
                'payment_method'      => $method,
                'reference_no'        => $referenceNo,
                'received_by'         => auth()->id(),
            ]);

            $totalPaid = NupPayment::where('nup_registration_id', $reg->id)->sum('amount');
            if ($totalPaid >= (float) $reg->scheme->nup_amount) {
                $reg->update(['status' => 'paid']);
            }

            return $payment;
        });
    }
}
