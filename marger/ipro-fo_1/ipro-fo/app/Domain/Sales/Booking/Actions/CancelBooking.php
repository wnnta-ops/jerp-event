<?php

declare(strict_types=1);

namespace App\Domain\Sales\Booking\Actions;

use App\Domain\Master\Unit\Enums\UnitStatus;
use App\Domain\Master\Unit\Models\Unit;
use App\Domain\Master\Unit\Services\UnitService;
use App\Domain\Sales\Booking\Enums\BookingStatus;
use App\Domain\Sales\Booking\Models\Booking;
use App\Domain\Sales\Booking\Services\BookingService;
use Illuminate\Support\Facades\DB;

final class CancelBooking
{
    public function __construct(
        private readonly BookingService $bookingService,
        private readonly UnitService $unitService,
    ) {}

    public function execute(Booking $booking, string $reason, float $penaltyAmount = 0): Booking
    {
        return DB::transaction(function () use ($booking, $reason, $penaltyAmount): Booking {
            // Dana yang sudah dibayar customer (paid_amount dari semua termin).
            $paidToDate = (float) DB::table('payment_schedules')
                ->where('booking_id', $booking->id)
                ->sum('paid_amount');

            // Refund = dana masuk − penalti (tidak boleh negatif).
            $refundAmount = max(0.0, round($paidToDate - $penaltyAmount, 2));

            // Catat permintaan pembatalan (audit).
            DB::table('cancellation_requests')->insert([
                'tenant_id'       => $booking->tenant_id,
                'booking_id'      => $booking->id,
                'requested_at'    => now()->toDateString(),
                'reason'          => $reason,
                'paid_to_date'    => $paidToDate,
                'penalty_amount'  => $penaltyAmount,
                'refund_amount'   => $refundAmount,
                'status'          => 'pending',
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);

            $this->bookingService->transitionTo($booking, BookingStatus::Cancelled, $reason);

            // Release unit
            $unit = Unit::find($booking->unit_id);
            if ($unit && $unit->status === UnitStatus::Booked) {
                $this->unitService->changeStatus($unit, UnitStatus::Available);
            }

            // Mark all outstanding payment schedules as void
            DB::table('payment_schedules')
                ->where('booking_id', $booking->id)
                ->whereIn('status', ['outstanding', 'partial'])
                ->update(['status' => 'void']);

            // Jika ada dana masuk → buat permintaan refund (pending) untuk Finance.
            // Sebelumnya tidak ada mekanisme refund booking sama sekali (bug).
            if ($refundAmount > 0) {
                DB::table('refunds')->insert([
                    'tenant_id'       => $booking->tenant_id,
                    'refund_number'   => 'REF-BK-'.$booking->id.'-'.now()->format('ymdHis'),
                    'refundable_type' => 'booking',
                    'refundable_id'   => $booking->id,
                    'amount'          => $refundAmount,
                    'requested_at'    => now()->toDateString(),
                    'status'          => 'pending',
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ]);
            }

            return $booking->refresh();
        });
    }
}
