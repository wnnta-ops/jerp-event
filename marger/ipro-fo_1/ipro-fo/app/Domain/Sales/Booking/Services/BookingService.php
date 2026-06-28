<?php

declare(strict_types=1);

namespace App\Domain\Sales\Booking\Services;

use App\Domain\Sales\Booking\Enums\BookingStatus;
use App\Domain\Sales\Booking\Models\Booking;
use App\Domain\Sales\Booking\Models\BookingStatusHistory;
use Illuminate\Support\Facades\DB;

final class BookingService
{
    /**
     * Transisi status booking dengan validasi state machine + audit history.
     */
    public function transitionTo(Booking $booking, BookingStatus $next, ?string $notes = null): Booking
    {
        $booking->status->assertCanTransitionTo($next);

        return DB::transaction(function () use ($booking, $next, $notes): Booking {
            BookingStatusHistory::create([
                'booking_id'  => $booking->id,
                'from_status' => $booking->status->value,
                'to_status'   => $next->value,
                'changed_by'  => auth()->id(),
                'notes'       => $notes,
                'changed_at'  => now(),
            ]);

            $booking->update(['status' => $next]);

            return $booking->refresh();
        });
    }

    /**
     * Hitung threshold approval diskon berdasarkan config.
     * Return: nama role yang harus approve, atau null kalau auto.
     */
    public function resolveDiscountApprover(float $discountPct): ?string
    {
        $thresholds = config('domain.discount.thresholds', []);
        foreach ($thresholds as $tier) {
            if ($discountPct >= $tier['min'] && $discountPct < $tier['max']) {
                return $tier['role'];
            }
        }

        return null;
    }
}
