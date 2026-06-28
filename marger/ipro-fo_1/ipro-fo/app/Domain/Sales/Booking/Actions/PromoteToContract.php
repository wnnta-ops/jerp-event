<?php

declare(strict_types=1);

namespace App\Domain\Sales\Booking\Actions;

use App\Domain\Foundation\Numerator\Services\NumeratorService;
use App\Domain\Sales\Booking\Enums\BookingStatus;
use App\Domain\Sales\Booking\Models\Booking;
use App\Domain\Sales\Booking\Services\BookingService;
use App\Domain\Sales\Contract\Models\Contract;
use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

final class PromoteToContract
{
    public function __construct(
        private readonly NumeratorService $numerator,
        private readonly BookingService $bookingService,
    ) {}

    public function execute(Booking $booking): Contract
    {
        if (! in_array($booking->status, [BookingStatus::Active, BookingStatus::PilihUnit], true)) {
            throw new BusinessRuleException(
                "Booking dengan status {$booking->status->label()} tidak bisa di-promote ke kontrak.",
                errorCode: 'BOOKING_NOT_PROMOTABLE',
            );
        }

        return DB::transaction(function () use ($booking): Contract {
            $code = $this->numerator->next('KON');

            $contract = Contract::create([
                'tenant_id'       => $booking->tenant_id,
                'booking_id'      => $booking->id,
                'contract_number' => $code,
                'signed_at'       => null,
                'status'          => 'draft',
                'snapshot'        => [
                    'customer_id'    => $booking->customer_id,
                    'unit_id'        => $booking->unit_id,
                    'unit_code'      => $booking->unit?->unit_code,
                    'total_price'    => (float) $booking->total_price,
                    'discount'       => (float) $booking->discount_amount,
                    'ppn'            => (float) $booking->ppn_amount,
                    'created_at'     => now()->toIso8601String(),
                ],
                'created_by'      => auth()->id(),
            ]);

            $this->bookingService->transitionTo($booking, BookingStatus::Ppjb, "Promote ke kontrak {$contract->contract_number}");

            return $contract;
        });
    }
}
