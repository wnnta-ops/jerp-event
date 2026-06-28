<?php

declare(strict_types=1);

namespace App\Domain\Finance\Tts\Services;

use App\Domain\Finance\Tts\Models\Tts;
use App\Domain\Sales\Booking\Models\PaymentSchedule;
use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

final class TtsAllocator
{
    /**
     * Auto-allocate ke jadwal terlama yang masih outstanding (FIFO).
     */
    public function autoAllocate(Tts $tts): void
    {
        if ($tts->booking_id === null) {
            return;
        }

        $remaining = (float) $tts->amount;

        $schedules = PaymentSchedule::query()
            ->where('booking_id', $tts->booking_id)
            ->whereIn('status', ['outstanding', 'partial'])
            ->orderBy('due_date')
            ->orderBy('installment_no')
            ->lockForUpdate()
            ->get();

        foreach ($schedules as $sch) {
            if ($remaining <= 0) {
                break;
            }
            $outstanding = (float) $sch->amount - (float) $sch->paid_amount;
            $alloc = min($remaining, $outstanding);
            if ($alloc <= 0) {
                continue;
            }

            $this->allocateAmount($tts, $sch, $alloc);
            $remaining -= $alloc;
        }
    }

    /**
     * Allocate manual: array of ['payment_schedule_id' => ..., 'amount' => ...].
     */
    public function manualAllocate(Tts $tts, array $allocations): void
    {
        $total = array_sum(array_column($allocations, 'amount'));
        if ($total > (float) $tts->amount) {
            throw new BusinessRuleException(
                "Total alokasi ({$total}) melebihi nominal TTS ({$tts->amount}).",
                errorCode: 'OVER_ALLOCATION',
            );
        }

        foreach ($allocations as $alloc) {
            $sch = PaymentSchedule::query()->lockForUpdate()->findOrFail($alloc['payment_schedule_id']);
            $outstanding = (float) $sch->amount - (float) $sch->paid_amount;
            if ($alloc['amount'] > $outstanding) {
                throw new BusinessRuleException(
                    "Alokasi pada schedule {$sch->id} ({$alloc['amount']}) melebihi outstanding ({$outstanding}).",
                    errorCode: 'OVER_ALLOCATION',
                );
            }
            $this->allocateAmount($tts, $sch, (float) $alloc['amount']);
        }
    }

    private function allocateAmount(Tts $tts, PaymentSchedule $sch, float $amount): void
    {
        DB::table('tts_allocations')->insert([
            'tts_receipt_id'      => $tts->id,
            'payment_schedule_id' => $sch->id,
            'amount'              => $amount,
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);

        $newPaid = (float) $sch->paid_amount + $amount;
        $sch->paid_amount = $newPaid;
        $sch->status      = $newPaid >= (float) $sch->amount ? 'paid' : 'partial';
        if ($sch->status === 'paid') {
            $sch->paid_at = now();
        }
        $sch->save();
    }
}
