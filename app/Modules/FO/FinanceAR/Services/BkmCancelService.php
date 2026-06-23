<?php

namespace App\Modules\FO\FinanceAR\Services;

use App\Modules\FO\FinanceAR\Models\Bkm;
use App\Modules\FO\MarketingSales\Models\KontrakTagihan;
use App\Modules\FO\Shared\Events\FoFinancialEvent;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Batalkan Kwitansi (BKM): status → BATAL & dana kembali ke STEP SEBELUMNYA.
 * - Sumber TTS  → TTS aktif kembali (status KONFIRMASI, lepas link BKM).
 * - Sumber Pelunasan (DP/ANG/TDP/BF) → pelunasan baris tagihan itu dikurangi
 *   (sisa kembali, baris muncul lagi untuk dibayar ulang).
 * Jurnal penerimaan kas dibalik (reversal) di GL.
 */
class BkmCancelService
{
    public function cancel(Bkm $bkm): void
    {
        if ($bkm->status === 'BATAL') {
            throw new RuntimeException('Kwitansi ini sudah dibatalkan.');
        }

        DB::transaction(function () use ($bkm) {
            $amount = (float) $bkm->amount;
            $glPosted = true;

            if ($bkm->tts_id) {
                // Dana kembali ke TTS: aktif lagi (re-convert), lepas link BKM.
                $tts = $bkm->tts;
                if ($tts) {
                    // Bila konversi sempat mengurangi sisa BF (BOOKING) → pulihkan.
                    if ($bkm->kontrak_tagihan_id) {
                        $this->restoreTagihan($bkm->kontrak_tagihan_id, $amount);
                    }
                    $tts->update(['status' => 'KONFIRMASI', 'bkm_id' => null]);
                    $glPosted = ! $tts->skip_gl_posting;
                }
            } elseif ($bkm->kontrak_tagihan_id) {
                // Dana kembali ke baris tagihan (DP/ANG/TDP/…).
                $this->restoreTagihan($bkm->kontrak_tagihan_id, $amount);
            }

            $bkm->update(['status' => 'BATAL']);

            // Reversal GL: balik jurnal KAS_MASUK (akun debet↔kredit ditukar).
            if ($glPosted) {
                $map = (array) config('fobo.mapping.KAS_MASUK');
                FoFinancialEvent::dispatch(
                    jenis: 'KAS_MASUK',
                    sourceModul: 'FO.FinanceAR',
                    sourceNo: $bkm->no_bkm.'-BATAL',
                    amount: $amount,
                    tgl: now()->toDateString(),
                    akunDebet: $map['kredit'] ?? null,
                    akunKredit: $map['debet'] ?? null,
                    keterangan: 'Pembatalan Kwitansi '.$bkm->no_bkm,
                    meta: ['bkm_id' => $bkm->id, 'reversal' => true],
                );
            }
        });
    }

    private function restoreTagihan(?int $tagihanId, float $amount): void
    {
        if (! $tagihanId) {
            return;
        }
        if ($t = KontrakTagihan::find($tagihanId)) {
            $t->pelunasan = max(0, (float) $t->pelunasan - $amount);
            $t->save();
        }
    }
}
