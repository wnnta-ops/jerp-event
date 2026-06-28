<?php

declare(strict_types=1);

namespace App\Modules\BO\IproBridge\Listeners;

use App\Modules\BO\IproBridge\Services\IproGlPoster;
use App\Modules\FO\Shared\Events\FoFinancialEvent;
use Illuminate\Support\Facades\Log;

/**
 * Posting event finansial FO → GL ipro-fo (ipro_bo). Berjalan sync; kegagalan
 * di-log tanpa menggagalkan transaksi FO.
 */
class PostFoEventToIproGl
{
    public function __construct(private IproGlPoster $poster)
    {
    }

    public function handle(FoFinancialEvent $event): void
    {
        try {
            $this->poster->post($event);
        } catch (\Throwable $e) {
            Log::warning("ipro GL post {$event->jenis}/{$event->sourceNo}: {$e->getMessage()}");
        }
    }
}
