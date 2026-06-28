<?php

declare(strict_types=1);

namespace App\Domain\Master\Customer\Listeners;

use App\Domain\Master\Customer\Events\CustomerCreated;
use Illuminate\Contracts\Queue\ShouldQueue;

final class SendWelcomeNotification implements ShouldQueue
{
    public string $queue = 'default';

    public function handle(CustomerCreated $event): void
    {
        // TODO: kirim WA / email welcome via NotificationDispatcher.
        // Placeholder — implement nanti saat NotificationDispatcher service siap.
        logger()->info('Customer baru terdaftar', [
            'customer_id' => $event->customer->id,
            'code'        => $event->customer->code,
            'full_name'   => $event->customer->full_name,
        ]);
    }
}
