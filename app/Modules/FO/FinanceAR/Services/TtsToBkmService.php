<?php

namespace App\Modules\FO\FinanceAR\Services;

use App\Modules\FO\FinanceAR\Models\Bkm;
use App\Modules\FO\FinanceAR\Models\Tts;
use App\Modules\FO\Shared\Events\FoFinancialEvent;
use App\Services\Numerator\NumeratorServiceInterface;
use Illuminate\Support\Facades\DB;

class TtsToBkmService
{
    /**
     * Convert a TTS into a BKM (Bukti Kas Masuk) atomically.
     * Updates TTS.status → JADI_BKM and links TTS.bkm_id → new BKM.
     */
    public static function convert(Tts $tts): Bkm
    {
        return DB::transaction(function () use ($tts) {
            $bkm = Bkm::create([
                'tenant_id' => $tts->tenant_id,
                'no_bkm' => app(NumeratorServiceInterface::class)->next('BKM'),
                'tgl_bkm' => now()->toDateString(),
                'tts_id' => $tts->id,
                'customer_id' => $tts->customer_id,
                'kontrak_id' => $tts->kontrak_id,
                'bank_id' => $tts->bank_id,
                'amount' => $tts->amount,
                'jenis_pembayaran' => $tts->jenis_pembayaran,
                'no_referensi' => $tts->no_referensi,
                'status' => 'POSTED',
                'keterangan' => 'Otomatis dari TTS '.$tts->no_tts,
            ]);

            $tts->update([
                'status' => 'JADI_BKM',
                'bkm_id' => $bkm->id,
            ]);

            // FOBO bridge: auto-post the cash receipt to the BO General Ledger —
            // KECUALI bila TTS ditandai skip_gl_posting (mis. UTJ yang sudah diposting
            // saat bayar Xendit) → hindari dobel-posting; "Buka Kuitansi" hanya cetakan.
            if (! $tts->skip_gl_posting) {
                FoFinancialEvent::dispatch(
                    jenis: 'KAS_MASUK',
                    sourceModul: 'FO.FinanceAR',
                    sourceNo: $bkm->no_bkm,
                    amount: (float) $bkm->amount,
                    tgl: now()->toDateString(),
                    keterangan: 'Penerimaan kas '.$bkm->no_bkm.' (TTS '.$tts->no_tts.')',
                    meta: ['bkm_id' => $bkm->id, 'tts_id' => $tts->id, 'customer_id' => $tts->customer_id],
                );
            }

            return $bkm;
        });
    }
}
