<?php

namespace App\Modules\FO\MarketingSales\Services;

use App\Modules\FO\FinanceAR\Models\Bkm;
use App\Modules\FO\MarketingSales\Models\Kontrak;
use App\Modules\FO\MarketingSales\Models\KontrakTagihan;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Reschedule Tagihan — ganti Skema Bayar kontrak SPR (selaras eProperty TagihanReschedule).
 *
 * Aturan (dari eProperty): baris bertipe ADM TIDAK disentuh (Biaya KPR, PPJB, HT, subsidi
 * data lama, …) — yang dijadwal ulang hanya BF/DP/ANG. Bedanya dengan eProperty: baris
 * yang sudah dibayar tidak dikunci; jadwal dibentuk ulang penuh dari skema baru lalu
 * pembayaran lama (pelunasan baris non-ADM) dialokasikan ulang berurutan ke baris baru,
 * dan kwitansinya ditautkan ulang ke baris tempat uangnya jatuh.
 *
 * Nilai Kontrak tidak berubah — reschedule hanya menyusun ulang jadwal.
 */
class RescheduleTagihanService
{
    public function __construct(private readonly KontrakGeneratorService $generator) {}

    /**
     * Jadwal baru tanpa menyimpan apa pun (dibentuk di dalam transaksi lalu di-rollback).
     *
     * @return array<int,array{tipe:string,nama_tagihan:string,jatuh_tempo:string,nilai:float,pelunasan:float}>
     */
    public function pratinjau(Kontrak $kontrak, int $skemaId, Carbon $tglMulai): array
    {
        DB::beginTransaction();
        try {
            return $this->bentuk(Kontrak::findOrFail($kontrak->id), $skemaId, $tglMulai)
                ->map(fn (KontrakTagihan $t) => self::baris($t))->all();
        } finally {
            DB::rollBack();
        }
    }

    /** Terapkan reschedule (dipanggil saat Approval Reschedule disetujui). */
    public function terapkan(Kontrak $kontrak, int $skemaId, Carbon $tglMulai): string
    {
        return DB::transaction(function () use ($kontrak, $skemaId, $tglMulai) {
            $k = Kontrak::lockForUpdate()->findOrFail($kontrak->id);
            $skemaLama = $k->skema?->name;
            $rows = $this->bentuk($k, $skemaId, $tglMulai);
            $k->refresh();

            return 'Jadwal Tagihan di-reschedule ('.$skemaLama.' → '.$k->skema?->name.'): '
                .$rows->where('tipe', '!=', 'ADM')->count().' baris baru, pembayaran Rp '
                .number_format((float) $rows->where('tipe', '!=', 'ADM')->sum('pelunasan'), 0, ',', '.').' dialokasikan ulang.';
        });
    }

    /** Ringkas satu baris tagihan untuk tampilan/approval. */
    public static function baris(KontrakTagihan $t): array
    {
        return [
            'tipe' => (string) $t->tipe,
            'nama_tagihan' => (string) $t->nama_tagihan,
            'jatuh_tempo' => optional($t->jatuh_tempo)->format('Y-m-d') ?? '',
            'nilai' => (float) $t->nilai,
            'pelunasan' => (float) $t->pelunasan,
        ];
    }

    /**
     * Inti reschedule — WAJIB dipanggil di dalam transaksi.
     *
     * @return Collection<int,KontrakTagihan> seluruh jadwal kontrak sesudah reschedule
     */
    private function bentuk(Kontrak $k, int $skemaId, Carbon $tglMulai): Collection
    {
        if ($k->tahap !== 'SPR') {
            throw new RuntimeException('Reschedule hanya untuk kontrak SPR (final). SPRS cukup ganti skema di form kontrak.');
        }
        // Dicek juga saat eksekusi approval: status bisa berubah selama pengajuan menunggu.
        if (in_array($k->status, ['BATAL', 'LUNAS'], true)) {
            throw new RuntimeException('Kontrak berstatus '.$k->status.' tidak bisa di-reschedule.');
        }

        $lama = $k->tagihan()->get();
        $dibuang = $lama->filter(fn (KontrakTagihan $t) => $t->tipe !== 'ADM' || self::subsidiGenerate($t));
        // Uang customer yang sudah masuk di baris yang dijadwal ulang (subsidi developer bukan uang customer).
        $dibayar = round((float) $dibuang->reject(fn (KontrakTagihan $t) => self::subsidiGenerate($t))->sum('pelunasan'), 2);
        $idDibuang = $dibuang->pluck('id')->all();
        // DP terbayar diteruskan ke generator: skema Free DP tidak menggratiskan DP yang sudah dibayar.
        $paidDp = $dibuang->where('tipe', 'DP')->filter(fn (KontrakTagihan $t) => (float) $t->pelunasan > 0.5)
            ->mapWithKeys(fn (KontrakTagihan $t) => [strtoupper(trim((string) $t->nama_tagihan)) => (float) $t->pelunasan])->all();

        // Kwitansi yang menunjuk baris lama — ditautkan ulang setelah jadwal baru terbentuk.
        $kwitansi = Bkm::query()->whereIn('kontrak_tagihan_id', $idDibuang)
            ->orderBy('tgl_bkm')->orderBy('id')->get();

        KontrakTagihan::whereIn('id', $idDibuang)->delete();

        // Generator membaca skema & tgl_kontrak dari kontrak dan menolak kontrak data lama.
        // Keduanya hanya diganti sementara; tgl_kontrak & data_lama dikembalikan di bawah.
        $tglAsli = $k->tgl_kontrak;
        $dataLamaAsli = $k->data_lama;
        $k->skema_id = $skemaId;
        $k->tgl_kontrak = $tglMulai;
        $k->data_lama = false;
        $k->save();

        $this->generator->tagihan($k, replace: false, paidDp: $paidDp);

        $k->tgl_kontrak = $tglAsli;
        $k->data_lama = $dataLamaAsli;
        $k->save();

        $baru = $k->tagihan()->whereNotIn('id', $lama->pluck('id'))
            ->where('tipe', '!=', 'ADM')->orderBy('urutan')->orderBy('id')->get();
        if ($baru->isEmpty()) {
            throw new RuntimeException('Skema baru tidak punya termin — atur di Setup Skema Bayar.');
        }

        // Alokasi ulang pembayaran: isi berurutan BF → DP → … → Pencairan.
        $sisa = $dibayar;
        foreach ($baru as $t) {
            $isi = round(min((float) $t->nilai, max(0.0, $sisa)), 2);
            $t->update(['pelunasan' => $isi]);
            $sisa = round($sisa - $isi, 2);
        }
        if ($sisa >= 0.5) {
            throw new RuntimeException('Pembayaran yang sudah masuk (Rp '.number_format($dibayar, 0, ',', '.')
                .') melebihi total jadwal skema baru — reschedule dibatalkan.');
        }

        $this->tautkanKwitansi($kwitansi, $baru);

        return $k->tagihan()->orderBy('urutan')->orderBy('id')->get();
    }

    /**
     * Kwitansi aktif ditautkan ke baris baru tempat rupiah pertamanya jatuh (urutan alokasi
     * sama dengan pengisian pelunasan); kwitansi BATAL dilepas.
     *
     * @param  Collection<int,Bkm>  $kwitansi
     * @param  Collection<int,KontrakTagihan>  $baru
     */
    private function tautkanKwitansi(Collection $kwitansi, Collection $baru): void
    {
        $batas = [];
        $jalan = 0.0;
        foreach ($baru as $t) {
            $jalan += (float) $t->nilai;
            $batas[] = [$jalan, $t->id];
        }

        $posisi = 0.0;
        foreach ($kwitansi as $bkm) {
            if ($bkm->status === 'BATAL') {
                $bkm->update(['kontrak_tagihan_id' => null]);

                continue;
            }
            $id = end($batas)[1];
            foreach ($batas as [$akhir, $tagihanId]) {
                if ($posisi < $akhir - 0.005) {
                    $id = $tagihanId;
                    break;
                }
            }
            $bkm->update(['kontrak_tagihan_id' => $id]);
            $posisi += (float) $bkm->amount;
        }
    }

    /** Baris UANG MASUK buatan generator (subsidi skema) — ikut skema, jadi dibentuk ulang. */
    private static function subsidiGenerate(KontrakTagihan $t): bool
    {
        return $t->tipe === 'ADM' && strtoupper(trim((string) $t->nama_tagihan)) === 'UANG MASUK'
            && preg_match('/^(Free DP|Subsidi developer)/', (string) $t->keterangan) === 1;
    }
}
