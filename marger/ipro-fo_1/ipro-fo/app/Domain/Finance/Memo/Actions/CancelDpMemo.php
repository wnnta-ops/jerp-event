<?php

declare(strict_types=1);

namespace App\Domain\Finance\Memo\Actions;

use App\Domain\Finance\Memo\Enums\MemoStatus;
use App\Domain\Finance\Memo\Models\Memo;
use Illuminate\Support\Facades\DB;

/**
 * Cancel Free DP / Subsidi DP memo.
 *
 *  - Soft-delete semua allocation yang dihasilkan memo ini
 *  - Recompute paid_amount per schedule
 *  - Set memo.status = Cancelled + cancelled_by/cancelled_at
 *
 * @return Memo memo yang sudah di-cancel
 */
final class CancelDpMemo
{
    public function execute(Memo $memo, string $cancelReason, ?int $cancelledById = null): Memo
    {
        if (! $memo->canBeCancelled()) {
            throw new \DomainException("Memo {$memo->memo_number} tidak bisa dibatalkan (status: {$memo->status->value}).");
        }

        return DB::transaction(function () use ($memo, $cancelReason, $cancelledById): Memo {
            // 1. Soft-delete semua allocation hasil memo ini (eager load schedule)
            $allocations = $memo->allocations()->with('schedule')->get();

            foreach ($allocations as $alloc) {
                $schedule = $alloc->schedule;
                $alloc->delete();
                $schedule?->recomputePaid();
            }

            // 2. Update memo status
            $memo->update([
                'status'       => MemoStatus::Cancelled,
                'cancelled_by' => $cancelledById ?? auth()->id() ?? 1,
                'cancelled_at' => now(),
                'reason'       => trim($memo->reason . "\n[CANCELLED] " . $cancelReason),
            ]);

            return $memo->fresh();
        });
    }
}
