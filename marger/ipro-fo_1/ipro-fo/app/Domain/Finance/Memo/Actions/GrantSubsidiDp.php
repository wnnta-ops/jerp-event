<?php

declare(strict_types=1);

namespace App\Domain\Finance\Memo\Actions;

use App\Domain\Finance\Memo\Enums\MemoStatus;
use App\Domain\Finance\Memo\Enums\MemoType;
use App\Domain\Finance\Memo\Models\Memo;
use App\Domain\Finance\Payment\Enums\AllocationSource;
use App\Domain\Finance\Payment\Enums\PaymentMethod;
use App\Domain\Finance\Payment\Enums\PaymentType;
use App\Domain\Finance\Payment\Models\PaymentAllocation;
use App\Domain\Foundation\Numerator\Services\NumeratorService;
use App\Domain\Sales\Booking\Models\Booking;
use Illuminate\Support\Facades\DB;

/**
 * Grant **Subsidi DP** (partial DP coverage).
 *
 * User specify total subsidi amount → distribusi proportional ke unpaid DP schedules.
 *
 * @return array{memo: Memo, allocations: \Illuminate\Support\Collection}
 */
final class GrantSubsidiDp
{
    public function __construct(
        private readonly NumeratorService $numerator,
    ) {}

    public function execute(Booking $booking, float $subsidiAmount, string $reason, ?int $approverId = null): array
    {
        if ($subsidiAmount <= 0) {
            throw new \InvalidArgumentException('Subsidi amount harus > 0.');
        }

        return DB::transaction(function () use ($booking, $subsidiAmount, $reason, $approverId): array {
            $dpSchedules = $booking->paymentSchedules()
                ->where('payment_type', PaymentType::DownPayment->value)
                ->whereIn('status', ['unpaid', 'partial'])
                ->orderBy('due_date')
                ->get();

            if ($dpSchedules->isEmpty()) {
                throw new \DomainException('Tidak ada DP schedule unpaid untuk subsidi.');
            }

            $totalOutstanding = $dpSchedules->sum(fn ($s) => max(0, (float) $s->amount - (float) $s->paid_amount));

            if ($subsidiAmount > $totalOutstanding) {
                throw new \DomainException(sprintf(
                    'Subsidi (Rp %s) lebih besar dari total DP outstanding (Rp %s).',
                    number_format($subsidiAmount, 0, ',', '.'),
                    number_format($totalOutstanding, 0, ',', '.'),
                ));
            }

            // 1. Create Memo
            $memo = Memo::create([
                'tenant_id'      => $booking->tenant_id,
                'memo_number'    => $this->numerator->next('MEMO'),
                'type'           => MemoType::SubsidiDp,
                'memo_date'      => now()->toDateString(),
                'amount'         => $subsidiAmount,
                'reference_type' => 'booking',
                'reference_id'   => $booking->id,
                'description'    => "Subsidi DP untuk Booking #{$booking->booking_number} sebesar Rp ".number_format($subsidiAmount, 0, ',', '.'),
                'reason'         => $reason,
                'status'         => MemoStatus::Approved,
                'approved_by'    => $approverId ?? auth()->id(),
                'approved_at'    => now(),
                'created_by'     => auth()->id() ?? 1,
            ]);

            // 2. Allocate FIFO (earliest due date first)
            $remaining   = $subsidiAmount;
            $allocations = collect();

            foreach ($dpSchedules as $schedule) {
                if ($remaining <= 0) break;

                $outstanding = max(0, (float) $schedule->amount - (float) $schedule->paid_amount);
                $allocateAmount = min($remaining, $outstanding);
                if ($allocateAmount <= 0) continue;

                $alloc = PaymentAllocation::create([
                    'tenant_id'           => $booking->tenant_id,
                    'payment_schedule_id' => $schedule->id,
                    'source_type'         => AllocationSource::Memo->value,
                    'source_id'           => $memo->id,
                    'payment_method'      => PaymentMethod::Diskon->value,
                    'amount'              => $allocateAmount,
                    'allocated_at'        => now()->toDateString(),
                    'reference_number'    => $memo->memo_number,
                    'notes'               => "Subsidi DP Memo {$memo->memo_number}",
                    'created_by'          => auth()->id() ?? 1,
                ]);

                $schedule->recomputePaid();
                $allocations->push($alloc);
                $remaining -= $allocateAmount;
            }

            return [
                'memo'        => $memo,
                'allocations' => $allocations,
            ];
        });
    }
}
