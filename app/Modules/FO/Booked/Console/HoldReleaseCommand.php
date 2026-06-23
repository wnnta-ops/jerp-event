<?php

namespace App\Modules\FO\Booked\Console;

use App\Modules\FO\Booked\Services\HoldService;
use Illuminate\Console\Command;

/**
 * Lepas unit yang masa hold-nya sudah lewat (tak ada transfer cocok dalam masa
 * hold) → kembali TERSEDIA. Dijadwalkan tiap jam (routes/console.php).
 */
class HoldReleaseCommand extends Command
{
    protected $signature = 'hold:release';

    protected $description = 'Lepas unit HOLD yang sudah lewat masa hold → TERSEDIA.';

    public function handle(HoldService $hold): int
    {
        $n = $hold->releaseExpired();
        $this->info("Hold kedaluwarsa dilepas: {$n} unit.");

        return self::SUCCESS;
    }
}
