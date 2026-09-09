<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Services;

use App\Domain\BusinessSupport\Models\BsCashAdvance;
use App\Domain\BusinessSupport\Models\BsCashAdvanceAllocation;
use App\Domain\BusinessSupport\Models\BsCreditCard;
use App\Domain\BusinessSupport\Models\BsCreditCardClosing;
use App\Domain\BusinessSupport\Models\BsCreditCardRequest;
use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * SALDO KARTU KREDIT/VA PER BULAN — Saldo Awal, Penambahan, Pemakaian, Sisa Saldo.
 *
 *   Saldo Awal  = Sisa Saldo bulan sebelumnya, HANYA bila bulan itu sudah di-closing.
 *   Penambahan  = nominal pembayaran Debit VA yang tersimpan pada bulan itu
 *                 (+ penyesuaian manual yang disetujui: topup − reduce).
 *   Pemakaian   = nominal Alokasi Kasbon pada bulan itu.
 *   Sisa Saldo  = Saldo Awal + Penambahan − Pemakaian.
 *
 * KENAPA BUKAN DARI BUKU BESAR. Sejak pembayaran Debit VA tidak berjurnal, akun kartu tidak lagi
 * bermutasi — menghitung "pemakaian" dari SUM(credit) akun kartunya akan menghasilkan NOL tanpa
 * satu galat pun. Yang benar-benar menggerakkan saldo kartu adalah dua dokumen: pembayaran bon
 * Debit VA (menambah) dan Alokasi Kasbon (mengurangi).
 *
 * KENAPA SALDO AWAL NOL BILA BELUM CLOSING. Itu memang yang diminta — saldo awal berasal dari
 * saldo akhir bulan sebelumnya APABILA sudah closing. Mewarisi angka berjalan bulan sebelumnya
 * tanpa closing berarti angka bulan ini bisa berubah setiap kali ada koreksi di bulan lampau,
 * dan laporan yang sudah dicetak diam-diam tidak lagi cocok.
 */
class CreditCardClosingService
{
    /**
     * Empat angka sebuah kartu pada satu bulan.
     *
     * @return array{saldo_awal:float, penambahan:float, pemakaian:float, sisa_saldo:float, closed:bool, closed_at:?string, prev_closed:bool}
     */
    public function ringkasan(int $cardId, int $year, int $month): array
    {
        return $this->ringkasanBanyak([$cardId], $year, $month)[$cardId];
    }

    /**
     * Empat angka untuk BANYAK kartu sekaligus — satu kueri per komponen, bukan satu per kartu.
     *
     * @param  array<int,int>  $cardIds
     * @return array<int,array{saldo_awal:float, penambahan:float, pemakaian:float, sisa_saldo:float, closed:bool, closed_at:?string, prev_closed:bool}>
     */
    public function ringkasanBanyak(array $cardIds, int $year, int $month): array
    {
        $cardIds = array_values(array_unique(array_map('intval', $cardIds)));
        if ($cardIds === []) {
            return [];
        }

        [$prevYear, $prevMonth] = self::bulanSebelumnya($year, $month);

        $awal = BsCreditCardClosing::whereIn('credit_card_id', $cardIds)
            ->where('period_year', $prevYear)->where('period_month', $prevMonth)
            ->pluck('sisa_saldo', 'credit_card_id');

        $tutup = BsCreditCardClosing::whereIn('credit_card_id', $cardIds)
            ->where('period_year', $year)->where('period_month', $month)
            ->get()->keyBy('credit_card_id');

        $bayar = $this->penambahanPembayaran($cardIds, $year, $month);
        $manual = $this->penambahanManual($cardIds, $year, $month);
        $pakai = $this->pemakaian($cardIds, $year, $month);

        $hasil = [];
        foreach ($cardIds as $id) {
            $saldoAwal = round((float) ($awal[$id] ?? 0), 2);
            $penambahan = round((float) ($bayar[$id] ?? 0) + (float) ($manual[$id] ?? 0), 2);
            $pemakaian = round((float) ($pakai[$id] ?? 0), 2);
            $closing = $tutup[$id] ?? null;

            $hasil[$id] = [
                // Bulan yang SUDAH di-closing menampilkan angka bekunya, bukan hitungan ulang:
                // itulah gunanya closing. Bila tidak, koreksi bon lama akan diam-diam mengubah
                // angka bulan yang sudah dilaporkan.
                'saldo_awal' => $closing ? (float) $closing->saldo_awal : $saldoAwal,
                'penambahan' => $closing ? (float) $closing->penambahan : $penambahan,
                'pemakaian'  => $closing ? (float) $closing->pemakaian : $pemakaian,
                'sisa_saldo' => $closing ? (float) $closing->sisa_saldo : round($saldoAwal + $penambahan - $pemakaian, 2),
                'closed'     => $closing !== null,
                'closed_at'  => $closing?->closed_at?->toDateTimeString(),
                'prev_closed' => $awal->has($id),
            ];
        }

        return $hasil;
    }

    /**
     * PENAMBAHAN dari pembayaran Debit VA yang tersimpan — dikelompokkan menurut TANGGAL TRANSFER.
     *
     * Tanggal transfer, bukan tanggal permintaan disetujui: yang diminta adalah "nominal
     * pembayaran Debit VA yang sudah disimpan", dan pembayaran itu bertanggal paid_date.
     *
     * @param  array<int,int>  $cardIds
     * @return \Illuminate\Support\Collection<int,float>
     */
    private function penambahanPembayaran(array $cardIds, int $year, int $month): Collection
    {
        return BsCashAdvance::whereIn('credit_card_id', $cardIds)
            ->where('payment_method', 'debit_va')
            ->whereNotNull('paid_date')
            ->whereYear('paid_date', $year)->whereMonth('paid_date', $month)
            ->selectRaw('credit_card_id, COALESCE(SUM(amount),0) s')
            ->groupBy('credit_card_id')->pluck('s', 'credit_card_id');
    }

    /**
     * Penyesuaian saldo MANUAL yang sudah disetujui (topup menambah, reduce mengurangi).
     *
     * Permintaan yang lahir otomatis dari bon sengaja DIKECUALIKAN: nominalnya sudah terhitung
     * lewat pembayarannya sendiri, dan menghitung keduanya berarti satu rupiah dihitung dua kali.
     * Pengecualian ini juga menyelamatkan data lama, yang permintaan topup otomatisnya sudah
     * terlanjur ada (dan sebagian sudah disetujui).
     *
     * @param  array<int,int>  $cardIds
     * @return \Illuminate\Support\Collection<int,float>
     */
    private function penambahanManual(array $cardIds, int $year, int $month): Collection
    {
        return BsCreditCardRequest::whereIn('credit_card_id', $cardIds)
            ->whereIn('type', ['topup', 'reduce'])
            ->where('status', 'approved')
            ->whereNotNull('approved_at')
            ->whereYear('approved_at', $year)->whereMonth('approved_at', $month)
            ->where(fn ($q) => $q->whereNull('source_type')
                ->orWhereNotIn('source_type', [CreditCardService::SUMBER_BON, CreditCardService::SUMBER_MASUK_SALDO]))
            ->selectRaw("credit_card_id, COALESCE(SUM(CASE WHEN type='reduce' THEN -amount ELSE amount END),0) s")
            ->groupBy('credit_card_id')->pluck('s', 'credit_card_id');
    }

    /**
     * PEMAKAIAN — total Alokasi Kasbon kartu itu pada bulan tersebut.
     *
     * @param  array<int,int>  $cardIds
     * @return \Illuminate\Support\Collection<int,float>
     */
    private function pemakaian(array $cardIds, int $year, int $month): Collection
    {
        return BsCashAdvanceAllocation::whereIn('credit_card_id', $cardIds)
            ->whereYear('alloc_date', $year)->whereMonth('alloc_date', $month)
            ->selectRaw('credit_card_id, COALESCE(SUM(amount),0) s')
            ->groupBy('credit_card_id')->pluck('s', 'credit_card_id');
    }

    /**
     * Riwayat bulanan sebuah kartu — dari bulan pertama yang punya mutasi sampai bulan diminta.
     *
     * Dirangkai BERURUTAN dan saling bersambung: sisa bulan lalu menjadi saldo awal bulan
     * berikutnya hanya bila bulan lalu sudah di-closing, persis seperti yang tampil di layar.
     *
     * @return array<int,array<string,mixed>>
     */
    public function riwayat(BsCreditCard $card, int $sampaiYear, int $sampaiMonth, int $maksBulan = 24): array
    {
        $mulai = $this->bulanPertama($card->id) ?? [$sampaiYear, $sampaiMonth];

        // Dibatasi supaya kartu yang sudah lama tidak melahirkan daftar tanpa ujung; batasnya
        // dihitung mundur dari bulan yang diminta agar yang terpotong justru yang paling tua.
        $baris = [];
        [$y, $m] = $mulai;
        while (count($baris) < $maksBulan * 4 && ($y < $sampaiYear || ($y === $sampaiYear && $m <= $sampaiMonth))) {
            $baris[] = ['year' => $y, 'month' => $m];
            [$y, $m] = self::bulanBerikutnya($y, $m);
        }
        $baris = array_slice($baris, -$maksBulan);

        return array_map(function (array $b) use ($card) {
            $r = $this->ringkasan((int) $card->id, $b['year'], $b['month']);

            return $b + $r + ['label' => self::labelBulan($b['year'], $b['month'])];
        }, $baris);
    }

    /**
     * CLOSING satu kartu satu bulan — membekukan keempat angkanya.
     *
     * Kunci baris kartunya dipegang selama snapshot dibuat: dua penutupan yang berjalan
     * bersamaan pada kartu yang sama akan menuliskan dua "saldo akhir" berbeda untuk bulan yang
     * sama, dan selisihnya tidak meninggalkan jejak apa pun.
     */
    public function close(BsCreditCard $card, int $year, int $month, ?int $userId = null, ?string $note = null): BsCreditCardClosing
    {
        return DB::transaction(function () use ($card, $year, $month, $userId, $note): BsCreditCardClosing {
            BsCreditCard::whereKey($card->id)->lockForUpdate()->first();

            $sudah = BsCreditCardClosing::where('credit_card_id', $card->id)
                ->where('period_year', $year)->where('period_month', $month)->first();
            if ($sudah) {
                throw new BusinessRuleException(
                    'Bulan '.self::labelBulan($year, $month).' pada kartu ini sudah di-closing.',
                    errorCode: 'CC_ALREADY_CLOSED',
                );
            }

            /* Bulan sebelumnya harus ditutup lebih dulu. Tanpa aturan ini, saldo awal bulan yang
               ditutup belakangan akan nol selamanya — dan angka bekunya salah sejak lahir. */
            [$py, $pm] = self::bulanSebelumnya($year, $month);
            $adaMutasiSebelumnya = $this->punyaMutasi($card->id, $py, $pm);
            $prevClosed = BsCreditCardClosing::where('credit_card_id', $card->id)
                ->where('period_year', $py)->where('period_month', $pm)->exists();
            if ($adaMutasiSebelumnya && ! $prevClosed) {
                throw new BusinessRuleException(
                    'Bulan '.self::labelBulan($py, $pm).' masih punya mutasi dan belum di-closing — tutup bulan itu lebih dulu.',
                    errorCode: 'CC_PREV_OPEN',
                );
            }

            $r = $this->ringkasan((int) $card->id, $year, $month);

            return BsCreditCardClosing::create([
                'credit_card_id' => $card->id,
                'period_year'    => $year,
                'period_month'   => $month,
                'saldo_awal'     => $r['saldo_awal'],
                'penambahan'     => $r['penambahan'],
                'pemakaian'      => $r['pemakaian'],
                'sisa_saldo'     => $r['sisa_saldo'],
                'note'           => $note,
                'closed_by'      => $userId,
                'closed_at'      => now(),
            ]);
        });
    }

    /**
     * Buka kembali closing sebuah bulan.
     *
     * Ditolak bila bulan SESUDAHNYA sudah ditutup: saldo awal bulan itu diambil dari sisa bulan
     * ini, jadi membuka yang di bawah akan membuat angka beku di atasnya tidak lagi bersambung.
     */
    public function reopen(BsCreditCardClosing $closing): void
    {
        [$ny, $nm] = self::bulanBerikutnya((int) $closing->period_year, (int) $closing->period_month);
        $adaSesudahnya = BsCreditCardClosing::where('credit_card_id', $closing->credit_card_id)
            ->where('period_year', $ny)->where('period_month', $nm)->exists();
        if ($adaSesudahnya) {
            throw new BusinessRuleException(
                'Bulan '.self::labelBulan($ny, $nm).' sudah di-closing — buka bulan itu lebih dulu.',
                errorCode: 'CC_NEXT_CLOSED',
            );
        }

        $closing->delete();
    }

    /** Apakah kartu punya mutasi (pembayaran VA / alokasi / penyesuaian manual) pada satu bulan? */
    private function punyaMutasi(int $cardId, int $year, int $month): bool
    {
        $r = $this->ringkasanBanyak([$cardId], $year, $month)[$cardId];

        return abs($r['penambahan']) >= 0.01 || abs($r['pemakaian']) >= 0.01;
    }

    /** Bulan paling tua yang punya mutasi pada kartu ini. @return array{0:int,1:int}|null */
    private function bulanPertama(int $cardId): ?array
    {
        $bayar = BsCashAdvance::where('credit_card_id', $cardId)->where('payment_method', 'debit_va')
            ->whereNotNull('paid_date')->min('paid_date');
        $alok = BsCashAdvanceAllocation::where('credit_card_id', $cardId)->min('alloc_date');

        $kandidat = array_values(array_filter([$bayar, $alok]));
        if ($kandidat === []) {
            return null;
        }
        $paling = min(array_map(fn ($d) => substr((string) $d, 0, 10), $kandidat));

        return [(int) substr($paling, 0, 4), (int) substr($paling, 5, 2)];
    }

    /** @return array{0:int,1:int} */
    public static function bulanSebelumnya(int $year, int $month): array
    {
        return $month === 1 ? [$year - 1, 12] : [$year, $month - 1];
    }

    /** @return array{0:int,1:int} */
    public static function bulanBerikutnya(int $year, int $month): array
    {
        return $month === 12 ? [$year + 1, 1] : [$year, $month + 1];
    }

    public static function labelBulan(int $year, int $month): string
    {
        $nama = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

        return ($nama[$month] ?? (string) $month).' '.$year;
    }
}
