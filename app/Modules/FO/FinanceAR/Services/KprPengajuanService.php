<?php

namespace App\Modules\FO\FinanceAR\Services;

use App\Modules\FO\FinanceAR\Models\KprPengajuan;
use App\Modules\FO\FinanceAR\Models\KprPengajuanBiaya;
use App\Modules\FO\MarketingSales\Models\Kontrak;
use App\Modules\FO\Shared\Events\FoFinancialEvent;
use App\Services\Numerator\NumeratorServiceInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Pengajuan KPR (Finance) berbasis SPR — murni pengajuan PEMBAYARAN biaya proses
 * KPR (free BPHTB/HT sebagian/KPR/notaris/sisa HT). Biaya HT sebagian & KPR
 * diproses cepat (Kas Keluar + posting GL), notaris & sisa HT menyusul.
 * TIDAK menyentuh SPR; pencairan KPR & pelunasan SPR ditangani modul BankLoan.
 */
class KprPengajuanService
{
    public function __construct(private readonly NumeratorServiceInterface $numerator) {}

    /** Biaya proses KPR standar yang otomatis diisikan ke daftar pembayaran. */
    public const JENIS = ['HT_SEBAGIAN', 'KPR', 'NOTARIS', 'SISA_HT'];

    /**
     * Buat Pengajuan KPR dari kontrak SPR (KPR). SPR tidak diubah. Item pembayaran:
     * 4 biaya proses (dari input) + SEMUA FREE dari SPR (gimmick) ditarik otomatis
     * — nominal FREE diisi saat Proses Pembayaran.
     *
     * @param  array<string,float>  $biayaNominal  nominal per jenis (HT_SEBAGIAN, ...)
     * @param  array<int,array{nama:string,nominal:float}>  $extra  pembayaran lain (manual)
     */
    public function ajukan(Kontrak $kontrak, array $biayaNominal, array $extra = [], ?string $keterangan = null): KprPengajuan
    {
        if ($kontrak->tahap !== 'SPR') {
            throw new RuntimeException('Pengajuan KPR hanya untuk kontrak tahap SPR.');
        }
        if (($kontrak->cara_bayar ?? '') !== 'KPR') {
            throw new RuntimeException('Kontrak ini bukan KPR (cara bayar: '.$kontrak->cara_bayar.').');
        }
        if (KprPengajuan::where('kontrak_id', $kontrak->id)->where('status', '!=', 'BATAL')->exists()) {
            throw new RuntimeException('Kontrak ini sudah punya Pengajuan KPR.');
        }

        return DB::transaction(function () use ($kontrak, $biayaNominal, $extra, $keterangan) {
            $p = KprPengajuan::create([
                'tenant_id' => $kontrak->tenant_id ?? 1,
                'no_pengajuan' => $this->numerator->next('PKPR'),
                'kontrak_id' => $kontrak->id,
                'tgl_pengajuan' => now()->toDateString(),
                'status' => 'DIAJUKAN',
                'keterangan' => $keterangan,
                'created_by' => Auth::id(),
            ]);

            // Biaya proses KPR (fixed).
            foreach (self::JENIS as $jenis) {
                $p->biaya()->create([
                    'jenis' => $jenis,
                    'nama' => KprPengajuanBiaya::LABELS[$jenis] ?? $jenis,
                    'nominal' => (float) ($biayaNominal[$jenis] ?? 0),
                    'status' => 'PENDING',
                ]);
            }

            // SEMUA FREE dari SPR (gimmick) — otomatis. Nominal diisi saat proses.
            foreach ($kontrak->gimmicks as $g) {
                $p->biaya()->create([
                    'jenis' => 'FREE',
                    'nama' => (string) $g->nama_barang,
                    'nominal' => (float) $g->total,
                    'status' => 'PENDING',
                ]);
            }

            // Pembayaran lain (manual ditambahkan finance).
            foreach ($extra as $item) {
                $nama = trim((string) ($item['nama'] ?? ''));
                if ($nama === '') {
                    continue;
                }
                $p->biaya()->create([
                    'jenis' => 'LAIN',
                    'nama' => $nama,
                    'nominal' => (float) ($item['nominal'] ?? 0),
                    'status' => 'PENDING',
                ]);
            }

            return $p->fresh('biaya');
        });
    }

    /** Proses satu pembayaran → Kas Keluar (developer bayar) + posting GL via FOBO. */
    public function prosesBiaya(KprPengajuanBiaya $biaya, array $opts = []): void
    {
        if ($biaya->status === 'TERPROSES') {
            throw new RuntimeException($biaya->label.' sudah diproses.');
        }
        // Nominal boleh disesuaikan saat proses (item yang tadinya "menyusul").
        $nominal = isset($opts['nominal']) ? (float) $opts['nominal'] : (float) $biaya->nominal;
        if ($nominal <= 0) {
            throw new RuntimeException('Nominal '.$biaya->label.' = 0, isi dulu nominalnya.');
        }

        DB::transaction(function () use ($biaya, $nominal, $opts) {
            $tgl = $opts['tgl'] ?? now()->toDateString();
            $biaya->update([
                'nominal' => $nominal,
                'status' => 'TERPROSES',
                'tgl_proses' => $tgl,
                'bank_id' => $opts['bank_id'] ?? null,
                'no_referensi' => $opts['no_referensi'] ?? null,
            ]);

            $kontrak = $biaya->pengajuan?->kontrak;
            FoFinancialEvent::dispatch(
                jenis: 'BIAYA_KPR',
                sourceModul: 'FO.FinanceAR',
                sourceNo: $biaya->pengajuan->no_pengajuan.'/'.$biaya->jenis,
                amount: (float) $biaya->nominal,
                tgl: $tgl,
                keterangan: $biaya->label.' — '.($kontrak?->no_kontrak ?? '').' (Pengajuan '.$biaya->pengajuan->no_pengajuan.')',
                meta: ['pengajuan_id' => $biaya->pengajuan_id, 'biaya_id' => $biaya->id, 'kontrak_id' => $kontrak?->id],
            );

            $this->refreshStatus($biaya->pengajuan);
        });
    }

    /** Tandai pengajuan SELESAI bila semua biaya (bernilai) sudah TERPROSES. */
    private function refreshStatus(KprPengajuan $p): void
    {
        $p->refresh();
        $semuaBiaya = $p->biaya()->where('status', '!=', 'TERPROSES')->where('nominal', '>', 0)->doesntExist();
        if ($semuaBiaya && $p->status !== 'SELESAI') {
            $p->update(['status' => 'SELESAI']);
        }
    }
}
