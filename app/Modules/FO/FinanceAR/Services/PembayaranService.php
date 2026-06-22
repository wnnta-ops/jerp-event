<?php

namespace App\Modules\FO\FinanceAR\Services;

use App\Modules\FO\FinanceAR\Models\Bkm;
use App\Modules\FO\MarketingSales\Models\KontrakTagihan;
use App\Modules\FO\Shared\Events\FoFinancialEvent;
use App\Services\Numerator\NumeratorServiceInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Pencatatan pembayaran per baris Jadwal Tagihan (UTJ/BF, Angsuran, Tambah DP).
 * Satu pembayaran: tambah `pelunasan` baris tagihan → terbit 1 Kwitansi (BKM)
 * yang ter-link ke baris tagihan → posting kas masuk ke BO (FOBO). Sisa tagihan
 * otomatis akurat sehingga Collection (Surat SP) ikut benar.
 */
class PembayaranService
{
    /** Map tipe baris tagihan → jenis_pembayaran BKM. */
    private const JENIS_MAP = [
        'BF' => 'BOOKING',
        'DP' => 'DP',
        'TDP' => 'DP',
        'ANG' => 'ANGSURAN',
        'ADM' => 'LAIN',
        'LAIN' => 'LAIN',
    ];

    /**
     * Catat pembayaran atas satu baris tagihan → terbit Kwitansi (BKM).
     *
     * @param  array<string,mixed>  $opts  tgl, bank_id, no_referensi, keterangan
     */
    public function bayar(KontrakTagihan $tagihan, float $nominal, array $opts = []): Bkm
    {
        $sisa = (float) $tagihan->sisa;
        if ($sisa <= 0.5) {
            throw new RuntimeException('Tagihan ini sudah lunas.');
        }
        // Tidak boleh melebihi sisa (pembayaran sebagian diperbolehkan).
        $nominal = min(max(0, $nominal), $sisa);
        if ($nominal <= 0) {
            throw new RuntimeException('Nominal pembayaran harus lebih dari 0.');
        }

        return DB::transaction(function () use ($tagihan, $nominal, $opts) {
            $tagihan->pelunasan = (float) $tagihan->pelunasan + $nominal;
            $tagihan->save();

            $kontrak = $tagihan->kontrak;
            $tgl = $opts['tgl'] ?? now()->toDateString();
            $jenis = self::JENIS_MAP[$tagihan->tipe] ?? 'LAIN';

            $bkm = Bkm::create([
                'tenant_id' => $kontrak->tenant_id ?? 1,
                'no_bkm' => app(NumeratorServiceInterface::class)->next('BKM'),
                'tgl_bkm' => $tgl,
                'customer_id' => $kontrak->customer_id,
                'kontrak_id' => $kontrak->id,
                'kontrak_tagihan_id' => $tagihan->id,
                'bank_id' => $opts['bank_id'] ?? null,
                'amount' => $nominal,
                'jenis_pembayaran' => $jenis,
                'no_referensi' => $opts['no_referensi'] ?? null,
                'status' => 'POSTED',
                'keterangan' => $opts['keterangan'] ?? ('Pembayaran '.$tagihan->nama_tagihan.' — '.$kontrak->no_kontrak),
            ]);

            FoFinancialEvent::dispatch(
                jenis: 'KAS_MASUK',
                sourceModul: 'FO.FinanceAR',
                sourceNo: $bkm->no_bkm,
                amount: (float) $bkm->amount,
                tgl: $tgl,
                keterangan: 'Penerimaan '.$tagihan->nama_tagihan.' '.$bkm->no_bkm.' ('.$kontrak->no_kontrak.')',
                meta: ['bkm_id' => $bkm->id, 'kontrak_id' => $kontrak->id, 'kontrak_tagihan_id' => $tagihan->id, 'customer_id' => $kontrak->customer_id],
            );

            return $bkm;
        });
    }
}
