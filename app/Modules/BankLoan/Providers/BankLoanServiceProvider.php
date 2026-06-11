<?php

namespace App\Modules\BankLoan\Providers;

use App\Modules\BankLoan\Listeners\SyncContractToBankLoan;
use App\Modules\FO\MarketingSales\Events\KontrakCreated;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class BankLoanServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Sync FO Kontrak (KPR) → BankLoan Contract.
        Event::listen(KontrakCreated::class, SyncContractToBankLoan::class);
    }
}
