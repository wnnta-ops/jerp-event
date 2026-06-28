<?php

declare(strict_types=1);

namespace App\Domain\Sales\Booking\Services;

use App\Domain\Sales\Booking\Models\Booking;
use App\Domain\Sales\Booking\Models\PaymentSchedule;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

final class ScheduleGenerator
{
    /**
     * Generate payment_schedules berdasarkan payment_scheme_terms.
     */
    public function generate(Booking $booking): void
    {
        $terms = DB::table('payment_scheme_terms')
            ->where('payment_scheme_id', $booking->payment_scheme_id)
            ->orderBy('term_no')
            ->get();

        $baseForSplit = (float) $booking->total_price;
        $startDate    = Carbon::parse($booking->booking_date);

        foreach ($terms as $term) {
            $amount = $term->basis === 'percent'
                ? round($baseForSplit * ((float) $term->value / 100), 2)
                : (float) $term->value;

            PaymentSchedule::create([
                'booking_id'     => $booking->id,
                'installment_no' => $term->term_no,
                'label'          => $term->label,
                'due_date'       => $startDate->copy()->addDays((int) $term->due_after_days)->toDateString(),
                'amount'         => $amount,
                'paid_amount'    => 0,
                'status'         => 'outstanding',
            ]);
        }
    }
}
