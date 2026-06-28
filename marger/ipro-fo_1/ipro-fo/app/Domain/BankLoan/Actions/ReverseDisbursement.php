<?php

declare(strict_types=1);

namespace App\Domain\BankLoan\Actions;

use App\Domain\BankLoan\Enums\KprStatus;
use App\Domain\BankLoan\Models\KprApplication;
use App\Domain\BankLoan\Models\KprRealization;
use App\Domain\Sales\Booking\Models\PaymentSchedule;
use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

/**
 * #65 — Hapus realisasi pencairan dengan reversal aman:
 *  - kembalikan paid_amount + status tiap jadwal angsuran yang teralokasi TTS,
 *  - hapus TTS auto + alokasinya, retensi, dan realisasi,
 *  - kembalikan status KPR (akad / disbursed).
 * Diblokir bila TTS sudah diposting.
 */
final class ReverseDisbursement
{
    public function execute(KprRealization $realization): void
    {
        DB::transaction(function () use ($realization): void {
            $tts = $realization->tts;
            if ($tts) {
                if ((string) $tts->status === 'posted') {
                    throw new BusinessRuleException(
                        'TTS pencairan sudah diposting — void dulu di modul TTS sebelum menghapus realisasi ini.',
                        errorCode: 'TTS_POSTED',
                    );
                }
                // Kembalikan paid_amount + status jadwal angsuran.
                foreach ($tts->allocations as $alloc) {
                    $sch = PaymentSchedule::find($alloc->payment_schedule_id);
                    if (! $sch) {
                        continue;
                    }
                    $newPaid = max(0, (float) $sch->paid_amount - (float) $alloc->amount);
                    $sch->paid_amount = $newPaid;
                    $sch->status = $newPaid <= 0 ? 'unpaid' : ($newPaid >= (float) $sch->amount ? 'paid' : 'partial');
                    if ($newPaid < (float) $sch->amount) {
                        $sch->paid_at = null;
                    }
                    $sch->save();
                }
                $tts->allocations()->delete();
                $tts->forceDelete();
            }

            $appId = $realization->kpr_application_id;
            // Hapus riwayat pelepasan retensi (anak dari retensi) sebelum retensinya.
            $retIds = $realization->retentions()->pluck('id');
            if ($retIds->isNotEmpty()) {
                \App\Domain\BankLoan\Models\KprRetentionRelease::whereIn('kpr_retention_id', $retIds)->delete();
            }
            $realization->retentions()->delete();
            $realization->delete();

            // Kembalikan status KPR.
            $app = KprApplication::find($appId);
            if ($app) {
                if ($app->realizations()->count() === 0) {
                    $app->update(['status' => KprStatus::AkadDone]);
                } elseif ((float) $app->realizations()->sum('disbursed_amount') + 0.01 < (float) $app->plafon_approved) {
                    $app->update(['status' => KprStatus::Disbursed]);
                }
            }
        });
    }
}
