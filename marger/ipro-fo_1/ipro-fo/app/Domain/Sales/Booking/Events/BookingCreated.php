<?php

declare(strict_types=1);

namespace App\Domain\Sales\Booking\Events;

use App\Domain\Sales\Booking\Models\Booking;
use Illuminate\Foundation\Events\Dispatchable;

final class BookingCreated
{
    use Dispatchable;

    public function __construct(
        public readonly Booking $booking,
        public readonly bool $needsApproval = false,
    ) {}
}
