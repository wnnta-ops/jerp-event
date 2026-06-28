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
 * Grant **Free DP** ke booking.
 *
 *  1. Create Memo type=free_dp dengan total amount = sum DP schedules
 *  2. Auto-approve (atau pending kalau perlu approval workflow) — controllable via $autoApprove
 *  3. Allocate ke setiap DP schedule yang masih unpaid, method=Diskon (DN)
 *  4. Recompute paid_amount per schedule
 *
 * @return array{memo: Memo, allocations: \Illuminate\Support\Collection}
 */
final class GrantFreeDp
{
    public function __construct(
        private readonly NumeratorService $numerator,
    ) {}

    public function execute(Booking $booking, string $reason, ?int $approverId = null): array
    {
        return DB::transaction(function () use ($booking, $reason, $approverId): array {
            // 1. Identifikasi DP schedules yang masih unpaid
            $dpSchedules = $booking->paymentSchedules()
                ->where('payment_type', PaymentType::DownPayment->value)
                ->whereIn('status', ['unpaid', 'partial'])
                ->get();

            if ($dpSchedules->isEmpty()) {
                throw new \DomainException('Tidak ada DP schedule yang bisa di-Free DP.');
            }

            $totalAmount = $dpSchedules->sum(fn ($s) => max(0, (float) $s->amount - (float) $s->paid_amount));

            if ($totalAmount <= 0) {
                throw new \DomainException('DP sudah lunas semua — tidak perlu Free DP.');
            }

            // 2. Create Memo (approved langsung untuk simplify; production-grade pakai approval flow)
            $memo = Memo::create([
                'tenant_id'      => $booking->tenant_id,
                'memo_number'    => $this->numerator->next('MEMO'),
                'type'           => MemoType::FreeDp,
                'memo_date'      => now()->toDateString(),
                'amount'         => $totalAmount,
                'reference_type' => 'booking',
                'reference_id'   => $booking->id,
                'description'    => "Free DP untuk Booking #{$booking->booking_number}",
                'reason'         => $reason,
                'status'         => MemoStatus::Approved, // TODO: change to PendingApproval kalau ada workflow
                'approved_by'    => $approverId ?? auth()->id(),
                'approved_at'    => now(),
                'created_by'     => auth()->id() ?? 1,
            ]);

            // 3. Allocate per DP schedule
            $allocations = collect();
            foreach ($dpSchedules as $schedule) {
                $outstanding = max(0, (float) $schedule->amount - (float) $schedule->paid_amount);
                if ($outstanding <= 0) continue;

                $alloc = PaymentAllocation::create([
                    'tenant_id'           => $booking->tenant_id,
                    'payment_schedule_id' => $schedule->id,
                    'source_type'         => AllocationSource::Memo->value,
                    'source_id'           => $memo->id,
                    'payment_method'      => PaymentMethod::Diskon->value,
                    'amount'              => $outstanding,
                    'allocated_at'        => now()->toDateString(),
                    'reference_number'    => $memo->memo_number,
                    'notes'               => "Free DP Memo {$memo->memo_number}",
                    'created_by'          => auth()->id() ?? 1,
                ]);

                $schedule->recomputePaid();
                $allocations->push($alloc);
            }

            return [
                'memo'        => $memo,
                'allocations' => $allocations,
            ];
        });
    }
}
