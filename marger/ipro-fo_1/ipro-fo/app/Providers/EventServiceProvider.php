<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Finance\Kuitansi\Listeners\AutoIssueKuitansi;
use App\Domain\Finance\Tts\Events\TtsPosted;
use App\Domain\Master\Customer\Events\CustomerCreated;
use App\Domain\Master\Customer\Listeners\SendWelcomeNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        CustomerCreated::class => [
            SendWelcomeNotification::class,
        ],
        TtsPosted::class => [
            AutoIssueKuitansi::class,
        ],
        \App\Domain\Sales\Booking\Events\BookingCreated::class => [
            \App\Domain\Commission\Listeners\CalculateOnBookingCreated::class,
        ],
        \App\Domain\Approval\Events\ApprovalApproved::class => [
            \App\Domain\Approval\Listeners\ApplyApprovedOutcome::class,
            \App\Domain\ApprovalInbox\Listeners\SyncMemoInternalStatus::class,
        ],
        \App\Domain\Approval\Events\ApprovalRejected::class => [
            \App\Domain\ApprovalInbox\Listeners\SyncMemoInternalStatus::class,
        ],
    ];

    public function boot(): void
    {
        //
    }
}
