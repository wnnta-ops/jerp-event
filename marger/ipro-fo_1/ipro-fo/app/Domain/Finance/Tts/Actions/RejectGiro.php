<?php

declare(strict_types=1);

namespace App\Domain\Finance\Tts\Actions;

use App\Domain\Finance\Tts\Models\Tts;
use App\Domain\Sales\Booking\Models\PaymentSchedule;
use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

/**
 * Reverse alokasi TTS giro yang ditolak bank.
 */
final class RejectGiro
{
    public function execute(Tts $tts, string $reason): Tts
    {
        if ($tts->payment_method !== 'giro') {
            throw new BusinessRuleException('TTS bukan giro.', errorCode: 'NOT_GIRO');
        }

        return DB::transaction(function () use ($tts, $reason): Tts {
            // Reverse semua alokasi
            $allocations = DB::table('tts_allocations')->where('tts_receipt_id', $tts->id)->get();
            foreach ($allocations as $a) {
                $sch = PaymentSchedule::lockForUpdate()->find($a->payment_schedule_id);
                if (! $sch) continue;
                $sch->paid_amount = max(0, (float) $sch->paid_amount - (float) $a->amount);
                $sch->status      = $sch->paid_amount > 0 ? 'partial' : 'outstanding';
                $sch->paid_at     = $sch->paid_amount >= (float) $sch->amount ? $sch->paid_at : null;
                $sch->save();
            }

            DB::table('tts_allocations')->where('tts_receipt_id', $tts->id)->delete();

            DB::table('tts_giros')->where('tts_receipt_id', $tts->id)->update([
                'status'        => 'rejected',
                'rejected_at'   => now()->toDateString(),
                'reject_reason' => $reason,
                'updated_at'    => now(),
            ]);

            $tts->update(['status' => 'cancelled', 'notes' => trim((string) $tts->notes."\n[GIRO REJECT] {$reason}")]);

            return $tts->refresh();
        });
    }
}
