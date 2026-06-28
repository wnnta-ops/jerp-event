<?php

declare(strict_types=1);

namespace App\Domain\Collection\Followup\Actions;

use App\Domain\Collection\Followup\Models\Followup;
use App\Domain\Sales\Booking\Models\Booking;

final class CreateFollowup
{
    public function execute(array $data): Followup
    {
        // Auto-resolve sales_person_id dari booking jika tidak diberikan
        if (! isset($data['sales_person_id']) && isset($data['booking_id'])) {
            $booking = Booking::find($data['booking_id']);
            $data['sales_person_id'] = $booking?->sales_person_id;
        }

        return Followup::create($data);
    }
}
