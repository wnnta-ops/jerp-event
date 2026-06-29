<?php

declare(strict_types=1);

namespace App\Modules\BO\IproBridge\Listeners;

use App\Modules\BO\IproBridge\Services\IproGlPoster;
use App\Modules\BO\IproBridge\Support\SyncFailureLogger;
use App\Modules\FO\Shared\Events\FoFinancialEvent;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/**
 * Posting event finansial FO → GL ipro-fo (ipro_bo). Berjalan SETELAH transaksi FO
 * commit (ShouldHandleEventsAfterCommit) → tak ada jurnal untuk transaksi yang batal
 * (cegah divergensi FO↔BO). Kegagalan dicatat ke outbox tanpa menggagalkan FO.
 */
class PostFoEventToIproGl implements ShouldHandleEventsAfterCommit
{
    public function __construct(private IproGlPoster $poster)
    {
    }

    public function handle(FoFinancialEvent $event): void
    {
        try {
            $this->poster->post($event);
        } catch (\Throwable $e) {
            SyncFailureLogger::record('gl', $event->sourceNo ?? null, $event->jenis ?? 'GL', $e);
        }
    }
}
