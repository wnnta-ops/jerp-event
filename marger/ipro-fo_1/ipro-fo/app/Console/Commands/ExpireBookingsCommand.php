<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Sales\Booking\Enums\BookingStatus;
use App\Domain\Sales\Booking\Models\Booking;
use App\Domain\Sales\Booking\Services\BookingService;
use Illuminate\Console\Command;

class ExpireBookingsCommand extends Command
{
    protected $signature   = 'booking:expire';
    protected $description = 'Cancel booking dengan status DRAFT yang sudah > 14 hari tanpa lanjut';

    public function handle(BookingService $service): int
    {
        $count = 0;
        Booking::query()
            ->where('status', BookingStatus::Draft->value)
            ->whereDate('created_at', '<', now()->subDays(14))
            ->chunkById(50, function ($bookings) use ($service, &$count): void {
                foreach ($bookings as $b) {
                    $service->transitionTo($b, BookingStatus::Cancelled, 'Auto-expired (14 hari tanpa lanjut)');
                    $count++;
                }
            });

        $this->info("Expired {$count} draft booking(s).");

        return self::SUCCESS;
    }
}
