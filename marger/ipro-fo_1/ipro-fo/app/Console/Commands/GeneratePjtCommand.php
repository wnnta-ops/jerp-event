<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Foundation\Numerator\Services\NumeratorService;
use App\Domain\Sales\Booking\Models\PaymentSchedule;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GeneratePjtCommand extends Command
{
    protected $signature   = 'pjt:generate';
    protected $description = 'Generate Piutang Jatuh Tempo dari schedules overdue';

    public function handle(NumeratorService $numerator): int
    {
        // Aggregate per customer
        $aggregates = PaymentSchedule::query()
            ->join('bookings', 'bookings.id', '=', 'payment_schedules.booking_id')
            ->where('payment_schedules.status', 'overdue')
            ->groupBy('bookings.customer_id', 'bookings.tenant_id')
            ->selectRaw('bookings.customer_id, bookings.tenant_id, SUM(payment_schedules.amount - payment_schedules.paid_amount) AS total_overdue, COUNT(*) AS n')
            ->get();

        $count = 0;
        foreach ($aggregates as $row) {
            $existing = DB::table('pjt_headers')
                ->where('customer_id', $row->customer_id)
                ->where('period_at', now()->toDateString())
                ->where('status', 'open')
                ->first();

            if ($existing) {
                continue;
            }

            $pjtNumber = $numerator->next('PJT');

            $headerId = DB::table('pjt_headers')->insertGetId([
                'tenant_id'        => $row->tenant_id,
                'pjt_number'       => $pjtNumber,
                'customer_id'      => $row->customer_id,
                'period_at'        => now()->toDateString(),
                'transaction_type' => 'jual',
                'total_overdue'    => $row->total_overdue,
                'status'           => 'open',
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);

            // Detail per schedule
            $schedules = PaymentSchedule::query()
                ->join('bookings', 'bookings.id', '=', 'payment_schedules.booking_id')
                ->where('payment_schedules.status', 'overdue')
                ->where('bookings.customer_id', $row->customer_id)
                ->select('payment_schedules.*')
                ->get();

            foreach ($schedules as $sch) {
                DB::table('pjt_details')->insert([
                    'pjt_header_id'        => $headerId,
                    'payment_schedule_id'  => $sch->id,
                    'amount'               => $sch->amount - $sch->paid_amount,
                    'created_at'           => now(),
                    'updated_at'           => now(),
                ]);
            }
            $count++;
        }

        $this->info("Generated {$count} PJT header(s).");

        return self::SUCCESS;
    }
}
