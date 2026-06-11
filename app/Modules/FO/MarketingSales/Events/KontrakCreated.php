<?php

namespace App\Modules\FO\MarketingSales\Events;

use App\Modules\FO\MarketingSales\Models\Kontrak;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dipancarkan saat FO Kontrak dibuat. Listener BankLoan men-sync kontrak ber-cara_bayar
 * KPR ke modul BankLoan (data FO → bisa diakadkan di BankLoan).
 */
class KontrakCreated
{
    use Dispatchable;

    public function __construct(public Kontrak $kontrak) {}
}
