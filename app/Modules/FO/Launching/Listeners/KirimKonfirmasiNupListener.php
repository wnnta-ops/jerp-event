<?php

namespace App\Modules\FO\Launching\Listeners;

use App\Modules\FO\Launching\Events\NupRegistered;
use App\Modules\FO\Launching\Jobs\KirimKonfirmasiNupJob;

class KirimKonfirmasiNupListener
{
    public function handle(NupRegistered $event): void
    {
        KirimKonfirmasiNupJob::dispatch($event->registration->id);
    }
}
