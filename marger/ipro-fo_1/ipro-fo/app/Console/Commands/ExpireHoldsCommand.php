<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Sales\Hold\Actions\ReleaseHold;
use App\Domain\Sales\Hold\Models\UnitHold;
use Illuminate\Console\Command;

class ExpireHoldsCommand extends Command
{
    protected $signature   = 'hold:expire';
    protected $description = 'Release semua unit_holds yang sudah expired';

    public function handle(ReleaseHold $action): int
    {
        $count = 0;
        UnitHold::query()
            ->where('status', 'active')
            ->where('expires_at', '<', now())
            ->chunkById(50, function ($holds) use ($action, &$count): void {
                foreach ($holds as $hold) {
                    $action->execute($hold, 'expired');
                    $count++;
                }
            });

        $this->info("Released {$count} expired hold(s).");

        return self::SUCCESS;
    }
}
