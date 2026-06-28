<?php

declare(strict_types=1);

namespace App\Domain\Commission\Listeners;

use App\Domain\Commission\Services\CommissionCalculator;
use App\Domain\Sales\Booking\Events\BookingCreated;
use Illuminate\Contracts\Queue\ShouldQueue;

final class CalculateOnBookingCreated implements ShouldQueue
{
    public string $queue = 'default';

    public function __construct(private readonly CommissionCalculator $calc) {}

    public function handle(BookingCreated $event): void
    {
        // Skip if needs approval (recalc setelah approved)
        if ($event->needsApproval) {
            return;
        }
        if ($event->booking->agent_id === null) {
            return;
        }
        $this->calc->calculate($event->booking);
    }
}
