<?php

declare(strict_types=1);

namespace App\Domain\Sales\Booking\Events;

use App\Domain\Sales\Booking\Models\Booking;
use Illuminate\Foundation\Events\Dispatchable;

final class BookingStatusChanged
{
    use Dispatchable;

    public function __construct(
        public readonly Booking $booking,
        public readonly string $fromStatus,
        public readonly string $toStatus,
    ) {}
}
