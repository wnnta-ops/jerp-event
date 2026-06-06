<?php

namespace App\Modules\BO\Providers;

use App\Modules\BO\FoBoBridge\Listeners\PostFoEventToGl;
use App\Modules\BO\GeneralLedger\Contracts\JurnalPostingServiceInterface;
use App\Modules\BO\GeneralLedger\Services\JurnalPostingService;
use App\Modules\FO\Shared\Events\FoFinancialEvent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Wiring for the BO (Back Office / Accounting) module:
 * binds the GL posting service and registers the FO→BO event subscriber.
 */
class BoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(JurnalPostingServiceInterface::class, JurnalPostingService::class);
    }

    public function boot(): void
    {
        // FOBO bridge: auto-post FO financial events to the GL (Fase 8).
        Event::listen(FoFinancialEvent::class, PostFoEventToGl::class);
    }
}
