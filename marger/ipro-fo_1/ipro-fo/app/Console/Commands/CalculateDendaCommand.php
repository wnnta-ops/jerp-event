<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Collection\Denda\Services\DendaConfig;
use App\Domain\Sales\Booking\Models\PaymentSchedule;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CalculateDendaCommand extends Command
{
    protected $signature   = 'billing:jatuh-tempo';
    protected $description = 'Tandai tagihan jatuh tempo dan hitung denda';

    public function handle(): int
    {
        // Parameter rumus dari "Perumusan Denda" (tabel parameters; fallback config).
        $rate  = DendaConfig::rate();
        $grace = DendaConfig::graceDays();

        $overdueCount = 0;
        $dendaCount   = 0;

        PaymentSchedule::query()
            ->whereIn('status', ['outstanding', 'partial'])
            ->whereDate('due_date', '<', now()->subDays($grace))
            ->chunkById(100, function ($schedules) use ($rate, &$overdueCount, &$dendaCount): void {
                foreach ($schedules as $sch) {
                    $sch->update(['status' => 'overdue']);
                    $overdueCount++;

                    $overdueDays = max(1, now()->diffInDays($sch->due_date, false) * -1);
                    $outstanding = (float) $sch->amount - (float) $sch->paid_amount;
                    $dendaAmount = DendaConfig::compute($outstanding, $overdueDays);

                    DB::table('dendas')->updateOrInsert(
                        ['payment_schedule_id' => $sch->id, 'computed_date' => now()->toDateString()],
                        [
                            'overdue_days'  => $overdueDays,
                            'rate_per_day'  => $rate,
                            'amount'        => $dendaAmount,
                            'status'        => 'open',
                            'created_at'    => now(),
                            'updated_at'    => now(),
                        ],
                    );
                    $dendaCount++;
                }
            });

        $this->info("Marked {$overdueCount} overdue, computed {$dendaCount} denda entries.");

        return self::SUCCESS;
    }
}
